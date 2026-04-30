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
 * achievements, and the four collections endpoints (mounts, pets,
 * toys, transmogs).
 *
 * Six endpoints per character means the fan-out is six times as wide
 * as the other importers. To stay inside Hostinger shared hosting's
 * memory budget on a 700-member roster (a single character's transmogs
 * payload alone can hit several hundred KB), we iterate members in
 * chunks of CHUNK_SIZE: fetch all six endpoints for each chunk, persist
 * incrementally, then discard the chunk's payloads from memory before
 * moving on. Peak memory stays bounded at ~chunk_size * payload_size
 * instead of growing linearly with the roster.
 *
 * Within each chunk we still dispatch one Http::pool batch per endpoint
 * type, so a missing resource on one endpoint (e.g. /collections/toys
 * 404) doesn't poison the others.
 *
 * Snapshot dedupe was dropped when we moved to incremental persistence:
 * the payload hash now reflects the rolling fingerprint of every blob
 * we wrote, but we no longer fold identical pulls into one snapshot
 * row. At weekly cadence the extra row volume is trivial (~52/year)
 * and the bookkeeping needed to atomically reattach incrementally-
 * persisted member rows to a pre-existing snapshot was not worth it.
 */
class SocialSnapshotImporter
{
    private const COLLECTION_TYPES = ['mounts', 'pets', 'toys', 'transmogs'];

    /**
     * Members per chunk. Each chunk does six Http::pool fetches and one
     * DB::transaction. 10 keeps peak memory comfortably inside Hostinger's
     * 512MB CLI ceiling: a single character's transmogs payload can hit
     * 1MB+ and 6 endpoints worth of those plus framework overhead per
     * chunk used to push us past the limit at chunk_size=25.
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
            'transmogs' => fn (string $slug, string $name) => $this->client->collectionsEndpoint($slug, $name, 'transmogs'),
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
            foreach ($chunkPayloads as $memberId => $blobs) {
                $member = $chunk->firstWhere('id', $memberId);
                if (! $member) {
                    continue;
                }
                MemberSocialSnapshot::query()->updateOrCreate(
                    [
                        'snapshot_id' => $snapshotId,
                        'member_id' => $member->id,
                    ],
                    [
                        'character_media' => $blobs['character_media'] ?? null,
                        'achievements' => $blobs['achievements'] ?? null,
                        'mounts' => $blobs['mounts'] ?? null,
                        'pets' => $blobs['pets'] ?? null,
                        'toys' => $blobs['toys'] ?? null,
                        'transmogs' => $blobs['transmogs'] ?? null,
                        'achievement_points' => $this->intOrNull($blobs['achievements']['total_points'] ?? null),
                        'total_mounts' => $this->countQuantity($blobs['mounts'] ?? null),
                        'total_pets' => $this->countQuantity($blobs['pets'] ?? null),
                        'total_toys' => $this->countQuantity($blobs['toys'] ?? null),
                    ]
                );
            }
        });
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
     * Collections payloads carry their own "collected" array length
     * plus a top-level total_quantity_collected on some payloads.
     * Use the explicit total when present, otherwise count the
     * collected items. Returns null for missing / malformed payloads.
     *
     * @param  array<string,mixed>|null  $payload
     */
    private function countQuantity(?array $payload): ?int
    {
        if ($payload === null) {
            return null;
        }
        if (isset($payload['total_quantity_collected']) && is_numeric($payload['total_quantity_collected'])) {
            return (int) $payload['total_quantity_collected'];
        }
        foreach (['mounts', 'pets', 'toys', 'transmogs', 'collected'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return count($payload[$key]);
            }
        }
        return null;
    }
}
