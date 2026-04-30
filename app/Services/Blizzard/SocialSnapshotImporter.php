<?php

namespace App\Services\Blizzard;

use App\Models\Member;
use App\Models\MemberSocialSnapshot;
use App\Models\Snapshot;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pull the per-character "social/cosmetic" data set: character media,
 * achievements, and three collections endpoints (mounts, pets, toys).
 *
 * Five endpoints per character means a wide fan-out. To stay inside
 * Hostinger shared hosting's memory budget on a 700-member roster, we
 * iterate members in chunks of CHUNK_SIZE: fetch every endpoint for
 * each chunk, slim the payloads, persist, then discard before moving
 * on. Peak memory stays bounded at ~chunk_size * slimmed_payload_size
 * instead of growing linearly with the roster.
 *
 * Within each chunk we still dispatch one Http::pool batch per endpoint
 * type, so a missing resource on one endpoint (e.g. /collections/toys
 * 404) doesn't poison the others.
 *
 * Storage shape is intentionally slim. Blizzard returns ~1.5MB per
 * character per pull when stored verbatim (criteria trees, full
 * mount/pet/toy objects with names + keys + sources, transmog
 * appearance sets). Production hit Hostinger's 3GB MySQL quota at
 * 4GB on this single table - see project_social_snapshots_constraints
 * memory and the slim* helpers below for what we keep:
 *   - achievements: [{id, completed_timestamp}, ...] + total_quantity
 *     + total_points. Glory-style achievements are still trackable by
 *     id; the per-criteria nesting (the bloat) is dropped.
 *   - mounts / pets / toys: integer ID arrays, no names or sources.
 *   - transmogs: not stored at all (column dropped). The UI never read
 *     it; if a future feature needs appearance tracking it should use a
 *     normalised (member_id, appearance_id) table, not a JSON blob.
 *
 * Retention: at the end of each pull we delete prior MemberSocialSnapshot
 * rows for the members we just wrote. Without this the table grows
 * unboundedly across weekly pulls (one row per member per snapshot)
 * even with the slim payloads.
 *
 * Snapshot dedupe was dropped when we moved to incremental persistence:
 * the payload hash is unique per run; identical pulls don't fold into
 * one snapshot row. At weekly cadence the extra row volume is trivial.
 */
class SocialSnapshotImporter
{
    private const COLLECTION_TYPES = ['mounts', 'pets', 'toys'];

    /**
     * Members per chunk. Each chunk does five Http::pool fetches and
     * one DB::transaction. 10 keeps peak memory inside Hostinger's
     * 512MB CLI ceiling with comfortable headroom now that the slim
     * payloads dropped per-row size by ~95%.
     */
    private const CHUNK_SIZE = 10;

    public function __construct(
        private readonly BlizzardClient $client,
        private readonly string $guildKey,
        private readonly int $requestDelayMs = 50,
        private readonly int $minLevel = 70,
        private readonly int $concurrency = 10,
    ) {}

    /**
     * @return array{
     *   snapshot_id:int,
     *   members_queried:int,
     *   matched:int,
     *   missing:int,
     *   errored:int,
     * }
     */
    public function pull(): array
    {
        if (! $this->client->isConfigured()) {
            throw new \RuntimeException(
                'Blizzard client credentials are not configured. '
                . 'Set BLIZZARD_CLIENT_ID and BLIZZARD_CLIENT_SECRET.'
            );
        }

        // Hostinger's PHP CLI defaults to 512MB and the chunked loop sits
        // close to that ceiling on a full 700-member roster (transmogs
        // payloads dominate). Bumping in-process so neither cron nor the
        // queue worker has to know to set -d memory_limit on the call.
        @ini_set('memory_limit', '768M');

        $members = Member::query()
            ->forGuild($this->guildKey)
            ->active()
            ->where('level', '>=', $this->minLevel)
            ->orderBy('id')
            ->get();

        $now = CarbonImmutable::now();

        // Create the snapshot row up front with a unique-per-run hash so
        // member rows can be linked as we persist them. The hash is
        // derived from the run timestamp + 8 random bytes; dedupe across
        // identical pulls is no longer attempted (see class doc).
        $snapshot = Snapshot::query()->create([
            'guild_key' => $this->guildKey,
            'source' => Snapshot::SOURCE_BLIZZARD_SOCIAL,
            'captured_at' => $now,
            'payload_hash' => hash('sha256', $now->toIso8601String() . bin2hex(random_bytes(8))),
            'member_count' => 0,
        ]);

        $endpointMakers = [
            'character_media' => fn (string $slug, string $name) => $this->client->characterMediaEndpoint($slug, $name),
            'achievements' => fn (string $slug, string $name) => $this->client->achievementsEndpoint($slug, $name),
            'mounts' => fn (string $slug, string $name) => $this->client->collectionsEndpoint($slug, $name, 'mounts'),
            'pets' => fn (string $slug, string $name) => $this->client->collectionsEndpoint($slug, $name, 'pets'),
            'toys' => fn (string $slug, string $name) => $this->client->collectionsEndpoint($slug, $name, 'toys'),
        ];

        $matched = 0;
        $missing = 0;
        $errored = 0;

        foreach ($members->chunk(self::CHUNK_SIZE) as $chunk) {
            $chunkPayloads = [];   // [memberId => [endpointType => body]]
            $chunkHadAny = [];     // memberIds in this chunk that returned any 200

            foreach ($endpointMakers as $type => $maker) {
                $jobs = $this->resolveJobs($chunk, $maker);
                $this->fanOut($jobs, $type, $chunkPayloads, $chunkHadAny, $errored);
            }

            $matched += count($chunkHadAny);
            $matchedSet = array_flip($chunkHadAny);
            foreach ($chunk as $member) {
                if (! isset($matchedSet[$member->id])) {
                    $missing++;
                }
            }

            $this->persistChunk($snapshot->id, $chunk, $chunkPayloads);

            // Drop the chunk's payloads before fetching the next chunk so
            // peak memory doesn't accumulate across the run. PHP's garbage
            // collector is reference-count-first and only sweeps cycles
            // periodically; nudging it explicitly between chunks keeps
            // memory flat instead of sawtoothing up to the limit.
            unset($chunkPayloads, $chunkHadAny);
            gc_collect_cycles();
        }

        $snapshot->forceFill(['member_count' => $matched])->save();

        return [
            'snapshot_id' => $snapshot->id,
            'members_queried' => $members->count(),
            'matched' => $matched,
            'missing' => $missing,
            'errored' => $errored,
        ];
    }

    /**
     * @param  Collection<int, Member>  $chunk
     * @param  array<int, array<string, array<string,mixed>>>  $chunkPayloads
     */
    private function persistChunk(int $snapshotId, Collection $chunk, array $chunkPayloads): void
    {
        if ($chunkPayloads === []) {
            return;
        }
        DB::transaction(function () use ($snapshotId, $chunk, $chunkPayloads): void {
            $writtenMemberIds = [];
            foreach ($chunkPayloads as $memberId => $blobs) {
                $member = $chunk->firstWhere('id', $memberId);
                if (! $member) {
                    continue;
                }
                $achievementsRaw = $blobs['achievements'] ?? null;
                $slimAchievements = $this->slimAchievements($achievementsRaw);

                MemberSocialSnapshot::query()->updateOrCreate(
                    [
                        'snapshot_id' => $snapshotId,
                        'member_id' => $member->id,
                    ],
                    [
                        'character_media' => $blobs['character_media'] ?? null,
                        'achievements' => $slimAchievements,
                        'mounts' => $this->slimCollection($blobs['mounts'] ?? null, 'mounts', ['mount', 'id']),
                        'pets' => $this->slimCollection($blobs['pets'] ?? null, 'pets', ['species', 'id']),
                        'toys' => $this->slimCollection($blobs['toys'] ?? null, 'toys', ['toy', 'id']),
                        'achievement_points' => $this->intOrNull($achievementsRaw['total_points'] ?? null),
                        'total_mounts' => $this->countQuantity($blobs['mounts'] ?? null, 'mounts'),
                        'total_pets' => $this->countQuantity($blobs['pets'] ?? null, 'pets'),
                        'total_toys' => $this->countQuantity($blobs['toys'] ?? null, 'toys'),
                    ]
                );
                $writtenMemberIds[] = $member->id;
            }

            // Retention: drop prior snapshot rows for members we just
            // wrote. The table is keyed (snapshot_id, member_id) so older
            // weeks' rows accumulate without cleanup, which is what
            // pushed us over Hostinger's 3GB quota previously. Keeping
            // only the latest row per member is enough for the
            // FarmPlanner's "who has X right now" question.
            if ($writtenMemberIds !== []) {
                MemberSocialSnapshot::query()
                    ->whereIn('member_id', $writtenMemberIds)
                    ->where('snapshot_id', '!=', $snapshotId)
                    ->delete();
            }
        });
    }

    /**
     * Reduce Blizzard's verbose achievements payload to just the IDs
     * + completion timestamps. Drops the per-criteria tree (the bloat
     * - sub-criteria for every step of every achievement) and the
     * category_progress + recent_events arrays which the UI never reads.
     *
     * Glory-style meta-achievements are still tracked: the FarmPlanner
     * (and any future "did this character clear achievement N" feature)
     * just needs to ask "is id N in the achievements list".
     *
     * @param  array<string,mixed>|null  $body
     * @return array{achievements:list<array{id:int, completed_timestamp:?int}>, total_quantity:?int, total_points:?int}|null
     */
    public function slimAchievements(?array $body): ?array
    {
        if ($body === null) {
            return null;
        }
        $list = is_array($body['achievements'] ?? null) ? $body['achievements'] : [];
        $slim = [];
        foreach ($list as $a) {
            if (! is_array($a)) {
                continue;
            }
            $id = $a['id'] ?? ($a['achievement']['id'] ?? null);
            if (! is_numeric($id)) {
                continue;
            }
            $ts = $a['completed_timestamp'] ?? null;
            $slim[] = [
                'id' => (int) $id,
                'completed_timestamp' => is_numeric($ts) ? (int) $ts : null,
            ];
        }
        return [
            'achievements' => $slim,
            'total_quantity' => $this->intOrNull($body['total_quantity'] ?? null),
            'total_points' => $this->intOrNull($body['total_points'] ?? null),
        ];
    }

    /**
     * Reduce a Blizzard collections payload to just the integer IDs
     * the FarmPlanner actually asks about. Each entry's id lives at
     * a different nested path per type:
     *   mounts -> entry.mount.id
     *   pets   -> entry.species.id
     *   toys   -> entry.toy.id
     *
     * @param  array<string,mixed>|null  $body
     * @param  list<string>  $idPath
     * @return array{0?:int}|null  shape is { $type: list<int> } when present
     */
    public function slimCollection(?array $body, string $type, array $idPath): ?array
    {
        if ($body === null) {
            return null;
        }
        $list = is_array($body[$type] ?? null) ? $body[$type] : [];
        $ids = [];
        foreach ($list as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $cursor = $entry;
            foreach ($idPath as $seg) {
                $cursor = is_array($cursor) ? ($cursor[$seg] ?? null) : null;
            }
            if (is_numeric($cursor)) {
                $ids[] = (int) $cursor;
            }
        }
        return [$type => $ids];
    }

    /**
     * @param  Collection<int, Member>  $members
     * @param  callable(string, string): array{url:string, headers:array<string,string>, query:array<string,string>}  $maker
     * @return array<int, array{member:Member, url:string, headers:array<string,string>, query:array<string,string>}>
     */
    private function resolveJobs(Collection $members, callable $maker): array
    {
        $jobs = [];
        foreach ($members as $member) {
            $charName = explode('-', $member->name, 2)[0] ?? null;
            if ($charName === null || $charName === '') {
                continue;
            }
            $slug = $member->realm_slug;
            if ($slug === null || $slug === '') {
                $slug = \App\Services\Raiderio\RealmSlug::slugifyCanonical($member->realm);
            }
            if ($slug === null || $slug === '') {
                $collapsed = \App\Services\Raiderio\RealmSlug::realmFromMemberName($member->name);
                $slug = \App\Services\Raiderio\RealmSlug::slugify($collapsed);
            }
            if ($slug === '') {
                continue;
            }
            $jobs[$member->id] = $maker($slug, $charName) + ['member' => $member];
        }
        return $jobs;
    }

    /**
     * @param  array<int, array{member:Member, url:string, headers:array<string,string>, query:array<string,string>}>  $jobs
     * @param  array<int, array<string, array<string,mixed>>>  $perMember
     * @param  list<int>  $hadAny
     */
    private function fanOut(array $jobs, string $type, array &$perMember, array &$hadAny, int &$errored): void
    {
        $batchSize = max(1, $this->concurrency);
        $batches = array_chunk($jobs, $batchSize, preserve_keys: true);
        $timeout = $this->client->timeoutSeconds();

        foreach ($batches as $batchIndex => $batch) {
            $responses = Http::pool(function (Pool $pool) use ($batch, $timeout) {
                $reqs = [];
                foreach ($batch as $memberId => $job) {
                    $reqs[] = $pool
                        ->as((string) $memberId)
                        ->acceptJson()
                        ->timeout($timeout)
                        ->withHeaders($job['headers'])
                        ->get($job['url'], $job['query']);
                }
                return $reqs;
            });

            foreach ($batch as $memberId => $job) {
                $resp = $responses[(string) $memberId] ?? null;

                if ($resp instanceof \Throwable) {
                    Log::warning('blizzard social fetch failed', [
                        'type' => $type,
                        'member' => $job['member']->name,
                        'message' => $resp->getMessage(),
                    ]);
                    $errored++;
                    continue;
                }
                if ($resp === null) {
                    $errored++;
                    continue;
                }
                if ($resp->status() === 404) {
                    // Don't increment $missing here - membership is
                    // judged across all six endpoints together below.
                    continue;
                }
                if (! $resp->successful()) {
                    Log::warning('blizzard social non-2xx', [
                        'type' => $type,
                        'member' => $job['member']->name,
                        'status' => $resp->status(),
                    ]);
                    $errored++;
                    continue;
                }
                $body = $resp->json();
                if (! is_array($body)) {
                    $errored++;
                    continue;
                }
                $perMember[$memberId][$type] = $body;
                if (! in_array($memberId, $hadAny, true)) {
                    $hadAny[] = $memberId;
                }
            }

            if ($this->requestDelayMs > 0 && $batchIndex < count($batches) - 1) {
                usleep($this->requestDelayMs * 1000);
            }
        }
    }

    private function intOrNull(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }

    /**
     * Total quantity of collected items in a Blizzard collections
     * payload. Prefers the explicit total_quantity_collected counter
     * when present, otherwise counts the entries under the type-keyed
     * list. Receives the verbose Blizzard payload (pre-slim), not the
     * slimmed shape we persist.
     *
     * @param  array<string,mixed>|null  $payload
     */
    private function countQuantity(?array $payload, string $type): ?int
    {
        if ($payload === null) {
            return null;
        }
        if (isset($payload['total_quantity_collected']) && is_numeric($payload['total_quantity_collected'])) {
            return (int) $payload['total_quantity_collected'];
        }
        if (isset($payload[$type]) && is_array($payload[$type])) {
            return count($payload[$type]);
        }
        return null;
    }
}
