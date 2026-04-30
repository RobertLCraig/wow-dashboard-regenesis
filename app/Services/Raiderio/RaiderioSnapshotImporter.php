<?php

namespace App\Services\Raiderio;

use App\Models\Member;
use App\Models\MemberMplusRun;
use App\Models\MemberSnapshot;
use App\Models\Snapshot;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pull a fresh Raider.IO snapshot for every active member in the local
 * roster. Unlike the wowaudit importer (which is bounded by the team
 * roster on wowaudit's side), this is roster-flexible: any character
 * GRM has marked active gets queried. That fits the heroic team's looser
 * roster - you don't have to add anyone to a "team" anywhere first.
 *
 * Fetches are dispatched in concurrent batches via Http::pool so a
 * 50-member roster fits comfortably under PHP's 30s wall-clock limit
 * even on shared hosting. Default concurrency is 10 (well under RIO's
 * unwritten ~600/min cap when a 100ms inter-batch delay is applied).
 *
 * Writes one Snapshot row (source='raiderio') and one MemberSnapshot row
 * per character that returned a 200. 404s (unknown char on RIO) are
 * skipped silently - common for low-level alts and recently-renamed chars.
 */
class RaiderioSnapshotImporter
{
    public function __construct(
        private readonly RaiderioClient $client,
        private readonly string $guildKey,
        /** Milliseconds to sleep between batches (NOT between requests). */
        private readonly int $requestDelayMs = 100,
        private readonly int $minLevel = 70,
        private readonly int $concurrency = 10,
        /**
         * Cap how many members get fetched per run. Null = no cap. With
         * a cap, oldest-RIO-first so a recurring schedule rotates
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
     * }
     */
    public function pull(): array
    {
        $members = $this->selectMembersToFetch();

        $now = CarbonImmutable::now();
        $perMemberPayloads = [];
        $matched = 0;
        $missing = 0;
        $errored = 0;
        $rateLimited = 0;
        $unknownRealms = [];

        // Build the per-member fetch plan up front so the pool builder
        // doesn't carry any logic of its own.
        $jobs = [];
        foreach ($members as $member) {
            [$charName, $collapsedRealm] = $this->splitName($member->name);
            if ($charName === null) {
                $errored++;
                continue;
            }

            // Prefer the canonical realm we've previously backfilled into
            // members.realm (always slug-correct). Fall back to the
            // collapsed realm parsed out of the GRM key, which goes
            // through the realm_slugs config map.
            if ($member->realm) {
                $slug = RealmSlug::slugifyCanonical($member->realm) ?? RealmSlug::slugify($collapsedRealm);
            } else {
                $slug = RealmSlug::slugify($collapsedRealm);
                if ($collapsedRealm !== null && ! isset(((array) config('raiderio.realm_slugs', []))[$collapsedRealm]) && $slug === strtolower($collapsedRealm)) {
                    $unknownRealms[$collapsedRealm] = true;
                }
            }

            $jobs[$member->id] = ['member' => $member, 'slug' => $slug, 'name' => $charName];
        }

        $batchSize = max(1, $this->concurrency);
        $batches = array_chunk($jobs, $batchSize, preserve_keys: true);
        $timeout = $this->client->timeoutSeconds();

        foreach ($batches as $batchIndex => $batch) {
            $responses = Http::pool(function (Pool $pool) use ($batch, $timeout) {
                $reqs = [];
                foreach ($batch as $memberId => $job) {
                    ['url' => $url, 'query' => $query] = $this->client->profileEndpoint($job['slug'], $job['name']);
                    $reqs[] = $pool
                        ->as((string) $memberId)
                        ->acceptJson()
                        ->timeout($timeout)
                        ->get($url, $query);
                }
                return $reqs;
            });

            foreach ($batch as $memberId => $job) {
                $resp = $responses[(string) $memberId] ?? null;

                if ($resp instanceof \Throwable) {
                    Log::warning('raiderio profile fetch failed', [
                        'member' => $job['member']->name, 'slug' => $job['slug'],
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
                    if ($resp->status() === 429) {
                        // Aggregated below into a single warning per pull -
                        // logging one line per 429 in a 700-member 429-storm
                        // floods the log to no useful end.
                        $rateLimited++;
                    } else {
                        Log::warning('raiderio non-2xx', [
                            'member' => $job['member']->name, 'slug' => $job['slug'],
                            'status' => $resp->status(), 'body' => mb_substr($resp->body(), 0, 200),
                        ]);
                    }
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

            // Inter-batch politeness delay. Only sleeps between batches,
            // not after the last one - first-batch wall time on a quiet
            // RIO is essentially the slowest single response.
            if ($this->requestDelayMs > 0 && $batchIndex < count($batches) - 1) {
                usleep($this->requestDelayMs * 1000);
            }
        }

        if ($unknownRealms !== []) {
            Log::info('raiderio realms used lowercase fallback', [
                'realms' => array_keys($unknownRealms),
                'hint' => 'Add to config/raiderio.php realm_slugs map if any returned 404.',
            ]);
        }

        if ($rateLimited > 0) {
            Log::warning('raiderio rate-limited (429)', [
                'rate_limited' => $rateLimited,
                'matched' => $matched,
                'members_queried' => $members->count(),
                'hint' => 'Lower RAIDERIO_SYNC_CONCURRENCY or raise RAIDERIO_REQUEST_DELAY_MS.',
            ]);
        }

        // Refuse to persist a snapshot when the pull was a total wash:
        // every member errored and none came back. The snapshots table
        // dedupes by payload_hash, so an empty payload would otherwise
        // create one all-zeros snapshot and then resurface its captured_at
        // every 3 hours, masking the failure on the dashboard. Throwing
        // here surfaces the failure to the command (FAILURE exit) and the
        // queue job (FAILED status), so the next manual or scheduled run
        // can fix it.
        if ($members->count() > 0 && $matched === 0 && $errored > 0) {
            throw new \RuntimeException(sprintf(
                'Raider.IO pull aborted: %d members queried, 0 matched, %d errored (%d rate-limited).',
                $members->count(),
                $errored,
                $rateLimited,
            ));
        }

        // Hash sorted by member id so payload order doesn't bust the
        // dedupe. Same payload across two pulls = same snapshot row.
        ksort($perMemberPayloads);
        $payloadHash = hash('sha256', json_encode($perMemberPayloads, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($perMemberPayloads, $payloadHash, $now, $matched, $missing, $errored, $members) {
            $snapshot = Snapshot::query()->firstOrCreate(
                [
                    'guild_key' => $this->guildKey,
                    'source' => Snapshot::SOURCE_RAIDERIO,
                    'payload_hash' => $payloadHash,
                ],
                [
                    'captured_at' => $now,
                    'member_count' => count($perMemberPayloads),
                ]
            );

            foreach ($perMemberPayloads as $memberId => $body) {
                $member = $members->firstWhere('id', $memberId);
                if (! $member) {
                    continue;
                }

                // Backfill the canonical realm onto the member (with
                // spaces and apostrophes preserved). RIO returns this
                // even when GRM only had the collapsed form, so we
                // build up a clean realm column over time.
                $canonicalRealm = is_string($body['realm'] ?? null) ? $body['realm'] : null;
                if ($canonicalRealm && $member->realm !== $canonicalRealm) {
                    $member->forceFill(['realm' => $canonicalRealm])->saveQuietly();
                }

                MemberSnapshot::query()->updateOrCreate(
                    [
                        'snapshot_id' => $snapshot->id,
                        'member_id' => $member->id,
                    ],
                    [
                        'level' => $member->level,
                        'rank_index' => $member->rank_index,
                        'last_online_at' => $member->last_online_at,
                        'recommend_promote' => $member->recommend_promote,
                        'recommend_demote' => $member->recommend_demote,
                        'recommend_kick' => $member->recommend_kick,
                        'raw_json' => $body,
                        'ilvl' => $this->equippedIlvl($body, $member),
                        'raid_progression_json' => $body['raid_progression'] ?? null,
                        'mplus_score' => $this->currentSeasonScore($body),
                        'mplus_keystone' => $this->highestWeeklyKeystone($body),
                    ]
                );

                $this->upsertRuns($body, $member->id, $snapshot->id, $now);
            }

            return [
                'snapshot_id' => $snapshot->id,
                'members_queried' => $members->count(),
                'matched' => $matched,
                'missing' => $missing,
                'errored' => $errored,
            ];
        });
    }

    /**
     * Pick members to fetch in this run. With $limit set, returns the
     * N members whose RIO snapshot is most stale (NULL last_seen first,
     * then oldest captured_at). Without a limit, returns every active
     * member - same shape as before.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Member>
     */
    private function selectMembersToFetch(): \Illuminate\Database\Eloquent\Collection
    {
        $latestPerMember = DB::table('member_snapshots as ms')
            ->select('ms.member_id', DB::raw('MAX(s.captured_at) as last_seen'))
            ->join('snapshots as s', 's.id', '=', 'ms.snapshot_id')
            ->where('s.source', Snapshot::SOURCE_RAIDERIO)
            ->groupBy('ms.member_id');

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
     * @return array{0:?string,1:?string}
     */
    private function splitName(string $memberName): array
    {
        $charName = explode('-', $memberName, 2)[0] ?? null;
        if ($charName === null || $charName === '') {
            return [null, null];
        }
        $realm = RealmSlug::realmFromMemberName($memberName);
        return [$charName, $realm];
    }

    /**
     * Raider.IO returns item_level_equipped as a float; we store ilvl as
     * an int (matches the wowaudit pathway). Round to nearest, not floor,
     * so 642.7 -> 643.
     *
     * The freshness gate drops the ilvl when either signal says the
     * gear sample is stale: GRM hasn't seen the char online within the
     * window, or RIO's gear `created_at` predates the window. Both are
     * relative durations so the gate doesn't need re-tuning each squish
     * or content tier.
     *
     * @param  array<string,mixed>  $body
     */
    private function equippedIlvl(array $body, Member $member): ?int
    {
        $gear = is_array($body['gear'] ?? null) ? $body['gear'] : null;
        $v = $gear['item_level_equipped'] ?? null;
        if (! is_numeric($v) || $v <= 0) {
            return null;
        }
        if (! $this->ilvlSampleIsFresh($gear, $member)) {
            return null;
        }
        return (int) round((float) $v);
    }

    /**
     * @param  array<string,mixed>  $gear
     */
    private function ilvlSampleIsFresh(array $gear, Member $member): bool
    {
        $windowDays = (int) (config('raiderio.stale_ilvl_window_days') ?? 0);
        if ($windowDays <= 0) {
            return true;
        }
        $cutoff = CarbonImmutable::now()->subDays($windowDays);

        // GRM-side: trust only if the char has been seen in-game recently.
        $lastOnline = $member->last_online_at;
        if ($lastOnline === null || $lastOnline->lt($cutoff)) {
            return false;
        }

        // RIO-side: trust only if RIO's gear sample itself is recent.
        // Catches the case where the char has been online (so GRM passes)
        // but RIO is still serving a months-old cached gear blob.
        $createdAt = $gear['created_at'] ?? null;
        if (! is_string($createdAt) || $createdAt === '') {
            return false;
        }
        try {
            $observed = CarbonImmutable::parse($createdAt);
        } catch (\Throwable) {
            return false;
        }
        return $observed->greaterThanOrEqualTo($cutoff);
    }

    /**
     * Current season RIO score across all specs (the headline number).
     *
     * @param  array<string,mixed>  $body
     */
    private function currentSeasonScore(array $body): ?float
    {
        $seasons = $body['mythic_plus_scores_by_season'] ?? null;
        if (! is_array($seasons) || $seasons === []) {
            return null;
        }
        $first = $seasons[0] ?? null;
        $all = $first['scores']['all'] ?? null;
        if (! is_numeric($all)) {
            return null;
        }
        return (float) $all;
    }

    /**
     * Highest mythic_level seen in this week's runs. Mirrors the wowaudit
     * `mplus_keystone` semantics so widgets can read either source.
     *
     * @param  array<string,mixed>  $body
     */
    private function highestWeeklyKeystone(array $body): ?int
    {
        $runs = $body['mythic_plus_weekly_highest_level_runs'] ?? null;
        if (! is_array($runs) || $runs === []) {
            return null;
        }
        $levels = array_filter(array_map(
            fn ($r) => is_array($r) && isset($r['mythic_level']) && is_int($r['mythic_level']) ? $r['mythic_level'] : null,
            $runs
        ));
        return $levels ? max($levels) : null;
    }

    /**
     * Persist individual M+ runs from any of RIO's run-bearing fields,
     * deduped by (member_id, completed_at). Same physical run can appear
     * in several fields at once - season best is also recent, weekly best
     * is also recent, etc. - so we walk fields in priority order
     * (most authoritative first) and let the first occurrence win.
     *
     * Two upserts per member at most: one for rows with a meaningful
     * score (so we update the score column on conflict), one for rows
     * without (so we don't blank out a previously-stored score from a
     * recent_runs hit that lacks one).
     *
     * @param  array<string,mixed>  $body
     */
    private function upsertRuns(array $body, int $memberId, int $snapshotId, CarbonImmutable $now): void
    {
        $seasonSlug = $this->currentSeasonSlug($body);
        $rows = $this->extractRuns($body, $memberId, $snapshotId, $seasonSlug, $now);
        if ($rows === []) {
            return;
        }

        // Split by whether we have a score, so the score column update on
        // conflict is conditional. Without this an authoritative score
        // already in the DB could get nulled out by a recent_runs row.
        $withScore = [];
        $withoutScore = [];
        foreach ($rows as $row) {
            if ($row['score'] === null) {
                $withoutScore[] = $row;
            } else {
                $withScore[] = $row;
            }
        }

        if ($withScore !== []) {
            DB::table('member_mplus_runs')->upsert(
                $withScore,
                ['member_id', 'completed_at'],
                ['last_seen_at', 'score', 'raw_json', 'updated_at'],
            );
        }
        if ($withoutScore !== []) {
            DB::table('member_mplus_runs')->upsert(
                $withoutScore,
                ['member_id', 'completed_at'],
                ['last_seen_at', 'raw_json', 'updated_at'],
            );
        }
    }

    /**
     * Walk RIO's run-bearing fields in priority order and return one row
     * per unique completed_at. Priority: season best > alternate >
     * weekly best > previous weekly best > recent. Scores backfill from
     * later sources when the priority winner had none.
     *
     * @param  array<string,mixed>  $body
     * @return list<array<string,mixed>>
     */
    private function extractRuns(array $body, int $memberId, int $snapshotId, ?string $seasonSlug, CarbonImmutable $now): array
    {
        $sources = [
            'mythic_plus_best_runs' => MemberMplusRun::SOURCE_SEASON_BEST,
            'mythic_plus_alternate_runs' => MemberMplusRun::SOURCE_ALTERNATE,
            'mythic_plus_weekly_highest_level_runs' => MemberMplusRun::SOURCE_WEEKLY_BEST,
            'mythic_plus_previous_weekly_highest_level_runs' => MemberMplusRun::SOURCE_PREV_WEEKLY_BEST,
            'mythic_plus_recent_runs' => MemberMplusRun::SOURCE_RECENT,
        ];

        $byKey = [];
        foreach ($sources as $field => $source) {
            $list = $body[$field] ?? null;
            if (! is_array($list)) {
                continue;
            }
            foreach ($list as $raw) {
                if (! is_array($raw)) {
                    continue;
                }
                $row = $this->normalizeRun($raw, $source, $memberId, $snapshotId, $seasonSlug, $now);
                if ($row === null) {
                    continue;
                }
                $key = $row['completed_at'];
                if (! isset($byKey[$key])) {
                    $byKey[$key] = $row;
                    continue;
                }
                if ($byKey[$key]['score'] === null && $row['score'] !== null) {
                    $byKey[$key]['score'] = $row['score'];
                }
            }
        }
        return array_values($byKey);
    }

    /**
     * Convert a single RIO run dict into a member_mplus_runs row.
     * Returns null if the row is missing the bare minimum (a parseable
     * completed_at and a mythic_level); we'd rather skip than persist
     * incoherent data.
     *
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>|null
     */
    private function normalizeRun(array $raw, string $source, int $memberId, int $snapshotId, ?string $seasonSlug, CarbonImmutable $now): ?array
    {
        $completedRaw = $raw['completed_at'] ?? null;
        if (! is_string($completedRaw) || $completedRaw === '') {
            return null;
        }
        try {
            $completedAt = CarbonImmutable::parse($completedRaw);
        } catch (\Throwable) {
            return null;
        }

        $level = $raw['mythic_level'] ?? null;
        if (! is_int($level) || $level <= 0) {
            return null;
        }

        $upgrades = $raw['num_keystone_upgrades'] ?? 0;
        $upgrades = is_int($upgrades) ? max(0, min(3, $upgrades)) : 0;

        $clear = $raw['clear_time_ms'] ?? null;
        $par = $raw['par_time_ms'] ?? null;
        $score = $raw['score'] ?? null;

        return [
            'member_id' => $memberId,
            'completed_at' => $completedAt->toDateTimeString(),
            'mythic_level' => $level,
            'dungeon_id' => is_int($raw['map_challenge_mode_id'] ?? null) ? $raw['map_challenge_mode_id'] : null,
            'dungeon_short_name' => is_string($raw['short_name'] ?? null) ? mb_substr($raw['short_name'], 0, 16) : null,
            'dungeon_name' => is_string($raw['dungeon'] ?? null) ? mb_substr($raw['dungeon'], 0, 64) : null,
            'clear_time_ms' => is_int($clear) && $clear >= 0 ? $clear : null,
            'par_time_ms' => is_int($par) && $par >= 0 ? $par : null,
            'num_keystone_upgrades' => $upgrades,
            'score' => is_numeric($score) ? round((float) $score, 1) : null,
            'affixes' => isset($raw['affixes']) && is_array($raw['affixes']) ? json_encode($raw['affixes']) : null,
            'season_slug' => $seasonSlug,
            'source' => $source,
            'first_seen_snapshot_id' => $snapshotId,
            'first_seen_at' => $now->toDateTimeString(),
            'last_seen_at' => $now->toDateTimeString(),
            'raw_json' => json_encode($raw),
            'created_at' => $now->toDateTimeString(),
            'updated_at' => $now->toDateTimeString(),
        ];
    }

    /**
     * Best-effort current-season slug. RIO's score blob carries it under
     * `mythic_plus_scores_by_season[0].season`. Used to tag every run
     * we ingest in this pull; technically a recent_runs row could span
     * the season boundary at reset, but the error is one-sided (a few
     * post-reset runs tagged with the prior season for ~24h until the
     * RIO blob updates), and not worth the per-row season inference.
     *
     * @param  array<string,mixed>  $body
     */
    private function currentSeasonSlug(array $body): ?string
    {
        $seasons = $body['mythic_plus_scores_by_season'] ?? null;
        if (! is_array($seasons) || $seasons === []) {
            return null;
        }
        $slug = $seasons[0]['season'] ?? null;
        return is_string($slug) && $slug !== '' ? mb_substr($slug, 0, 32) : null;
    }
}
