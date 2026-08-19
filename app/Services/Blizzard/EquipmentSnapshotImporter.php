<?php

namespace App\Services\Blizzard;

use App\Models\Member;
use App\Models\MemberEquipmentSnapshot;
use App\Models\Snapshot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pull /profile/wow/character/.../equipment for every active member
 * and store the per-piece gear blob keyed by snapshot + member.
 *
 * Rationale: the profile summary only carries the rolled-up
 * equipped_item_level. The equipment endpoint returns the full
 * equipped_items array - one entry per slot, with item id, slot, item
 * level, enchantments, sockets, and bonus list. This is what powers
 * pre-raid readiness checks (missing enchants, empty sockets, off-spec
 * stat priorities) without needing wowaudit opt-in coverage.
 *
 * Same fan-out shape as BlizzardSnapshotImporter (Http::pool batched
 * for Hostinger's 30s wall clock), separate snapshot row stamped
 * source='blizzard_equipment'. Keeping it independent from the profile
 * importer means equipment can be pulled at a different cadence later
 * without churning shared code.
 */
class EquipmentSnapshotImporter
{
    public function __construct(
        private readonly BlizzardClient $client,
        private readonly string $guildKey,
        private readonly int $requestDelayMs = 50,
        private readonly int $minLevel = 70,
        private readonly int $concurrency = 10,
        /**
         * Cap how many members get fetched per run. Null = no cap (one
         * sweep of the whole roster). With a cap, members are picked
         * by oldest-equipment-first so a recurring schedule rotates
         * through the roster instead of always re-pulling the same N.
         */
        private readonly ?int $limit = null,
    ) {}

    /**
     * @return array{
     *   snapshot_id:int,
     *   members_queried:int,
     *   matched:int,
     *   missing:int,
     *   errored:int,
     *   unchanged:int,
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

        $members = $this->selectMembersToFetch();

        $now = CarbonImmutable::now();
        $perMemberPayloads = [];
        $matched = 0;
        $missing = 0;
        $errored = 0;

        $jobs = [];
        foreach ($members as $member) {
            $endpoint = $this->resolveEndpoint($member);
            if ($endpoint === null) {
                $errored++;
                continue;
            }
            $jobs[$member->id] = $endpoint + ['member' => $member];
        }

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
                    Log::warning('blizzard equipment fetch failed', [
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
                    $missing++;
                    continue;
                }

                if (! $resp->successful()) {
                    Log::warning('blizzard equipment non-2xx', [
                        'member' => $job['member']->name,
                        'status' => $resp->status(),
                        'body' => mb_substr((string) $resp->body(), 0, 200),
                    ]);
                    $errored++;
                    continue;
                }

                $body = $resp->json();
                if (! is_array($body)) {
                    $errored++;
                    continue;
                }

                $perMemberPayloads[$memberId] = $body;
                $matched++;
            }

            if ($this->requestDelayMs > 0 && $batchIndex < count($batches) - 1) {
                usleep($this->requestDelayMs * 1000);
            }
        }

        ksort($perMemberPayloads);
        $payloadHash = hash('sha256', json_encode($perMemberPayloads, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($perMemberPayloads, $payloadHash, $now, $matched, $missing, $errored, $members) {
            // updateOrCreate, not firstOrCreate: dedup makes repeat batches
            // return byte-identical payloads, so the hash now recurs routinely
            // and firstOrCreate would hand back a snapshot still carrying its
            // original captured_at. Staleness is read off that column, so the
            // members in a recurring batch would rank oldest forever and the
            // sweep would stop rotating. Re-observing the same gear now is a
            // new observation, so the timestamp moves.
            $snapshot = Snapshot::query()->updateOrCreate(
                [
                    'guild_key' => $this->guildKey,
                    'source' => Snapshot::SOURCE_BLIZZARD_EQUIPMENT,
                    'payload_hash' => $payloadHash,
                ],
                [
                    'captured_at' => $now,
                    'member_count' => count($perMemberPayloads),
                ]
            );

            $previousRows = $this->latestRowsFor(array_keys($perMemberPayloads));
            $unchanged = 0;

            foreach ($perMemberPayloads as $memberId => $body) {
                $member = $members->firstWhere('id', $memberId);
                if (! $member) {
                    continue;
                }

                $equipped = $this->intOrNull($body['equipped_item_level'] ?? null);
                $average = $this->intOrNull($body['average_item_level'] ?? null);
                $pieces = is_array($body['equipped_items'] ?? null)
                    ? $body['equipped_items']
                    : [];

                $previous = $previousRows->get($member->id);

                // Gear changes rarely, and each blob is tens of KB. Writing an
                // identical copy per member per half-hour is what filled the
                // 3 GB database cap twice (2026-07-08 and again on 07-10), so
                // when nothing moved we carry the member's existing row onto
                // this snapshot rather than inserting a duplicate of it.
                //
                // Moving it - rather than leaving it where it was - is the part
                // that matters: selectMembersToFetch ranks staleness by the
                // captured_at of the snapshot a member's latest row hangs off,
                // so a skipped write that left the row behind would pin those
                // members at the front of the queue forever and the sweep would
                // stop rotating while still looking busy.
                //
                // Loose == on purpose: the payload round-trips through a JSON
                // column, which is free to reorder object keys, and === would
                // read that as a change and defeat the whole dedup.
                if ($previous
                    && $previous->equipped_ilvl === $equipped
                    && $previous->average_ilvl === $average
                    && $previous->pieces == $pieces
                ) {
                    if ($previous->snapshot_id !== $snapshot->id) {
                        $previous->update(['snapshot_id' => $snapshot->id]);
                    }
                    $unchanged++;
                    continue;
                }

                MemberEquipmentSnapshot::query()->updateOrCreate(
                    [
                        'snapshot_id' => $snapshot->id,
                        'member_id' => $member->id,
                    ],
                    [
                        'equipped_ilvl' => $equipped,
                        'average_ilvl' => $average,
                        'pieces' => $pieces,
                    ]
                );
            }

            return [
                'snapshot_id' => $snapshot->id,
                'members_queried' => $members->count(),
                'matched' => $matched,
                'missing' => $missing,
                'errored' => $errored,
                'unchanged' => $unchanged,
            ];
        });
    }

    /**
     * Each named member's most recent equipment row, keyed by member id.
     *
     * Bounded to one row per member by design: a member accumulates a row per
     * genuine gear change, and loading their whole history to compare against
     * the newest one would put the pruned-but-still-present back catalogue
     * back into memory on every sweep.
     *
     * @param  list<int>  $memberIds
     * @return \Illuminate\Support\Collection<int, MemberEquipmentSnapshot>
     */
    private function latestRowsFor(array $memberIds): \Illuminate\Support\Collection
    {
        if ($memberIds === []) {
            return collect();
        }

        return MemberEquipmentSnapshot::query()
            ->whereIn('id', DB::table('member_equipment_snapshots')
                ->selectRaw('MAX(id)')
                ->whereIn('member_id', $memberIds)
                ->groupBy('member_id'))
            ->get()
            ->keyBy('member_id');
    }

    /**
     * Pick members to fetch in this run. With $limit set, returns the
     * N members whose equipment is most stale: never-imported first
     * (NULL last_seen), then ordered by oldest captured_at. Without a
     * limit, returns every active member - same shape as before.
     *
     * Stable secondary sort by member.id breaks ties so two members
     * that share a NULL last_seen always come back in the same order
     * across runs (no member "jumps the queue" by accident).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Member>
     */
    private function selectMembersToFetch(): \Illuminate\Database\Eloquent\Collection
    {
        $latestPerMember = DB::table('member_equipment_snapshots as mes')
            ->select('mes.member_id', DB::raw('MAX(s.captured_at) as last_seen'))
            ->join('snapshots as s', 's.id', '=', 'mes.snapshot_id')
            ->where('s.source', Snapshot::SOURCE_BLIZZARD_EQUIPMENT)
            ->groupBy('mes.member_id');

        $query = Member::query()
            ->forGuild($this->guildKey)
            ->active()
            ->where('level', '>=', $this->minLevel)
            ->leftJoinSub($latestPerMember, 'latest', fn ($j) => $j->on('latest.member_id', '=', 'members.id'))
            ->orderByRaw('latest.last_seen IS NULL DESC')
            ->orderBy('latest.last_seen', 'asc')
            ->orderBy('members.id', 'asc')
            ->select('members.*');

        if ($this->limit !== null && $this->limit > 0) {
            $query->limit($this->limit);
        }

        return $query->get();
    }

    /**
     * Decide what realm slug to query. Prefer the canonical
     * realm_slug column populated by the guild roster importer; fall
     * back to deriving from the legacy "Char-Realm" name plus RIO's
     * realm map for members that haven't been hit by a roster pull
     * yet (e.g. transferred-in alts visible in GRM but not yet in
     * Blizzard's cached roster snapshot).
     *
     * @return array{url:string, headers:array<string,string>, query:array<string,string>}|null
     */
    private function resolveEndpoint(Member $member): ?array
    {
        $charName = explode('-', $member->name, 2)[0] ?? null;
        if ($charName === null || $charName === '') {
            return null;
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
            return null;
        }

        return $this->client->equipmentEndpoint($slug, $charName);
    }

    private function intOrNull(mixed $v): ?int
    {
        if (! is_numeric($v) || $v <= 0) {
            return null;
        }
        return (int) round((float) $v);
    }
}
