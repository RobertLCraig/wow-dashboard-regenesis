<?php

namespace App\Services\Blizzard;

use App\Models\Member;
use App\Models\MemberSnapshot;
use App\Models\Snapshot;
use App\Services\Raiderio\RealmSlug;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pull a fresh Blizzard character profile for every active member.
 *
 * Mirrors the Raider.IO importer (Http::pool with batched concurrency
 * to fit under Hostinger's 30s wall-clock cap), but the data is much
 * fresher: Blizzard updates the equipped_item_level on character
 * logout, with no scraping cache in between. The profile summary also
 * carries `last_login_timestamp`, which we use as the RIO-equivalent
 * freshness stamp for the recency gate so parked alts don't leak
 * pre-squish gear onto the roster.
 *
 * Writes one Snapshot row (source='blizzard') and one MemberSnapshot
 * row per character that returned a 200. 404s (unknown / never
 * logged-in chars, low-level alts) are skipped silently.
 */
class BlizzardSnapshotImporter
{
    public function __construct(
        private readonly BlizzardClient $client,
        private readonly string $guildKey,
        /** Milliseconds to sleep between batches (NOT between requests). */
        private readonly int $requestDelayMs = 50,
        private readonly int $minLevel = 70,
        private readonly int $concurrency = 10,
        /**
         * Cap how many members get fetched per run. Null = no cap. With
         * a cap, oldest-profile-first so a recurring schedule rotates
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
        $unknownRealms = [];

        $jobs = [];
        foreach ($members as $member) {
            [$charName, $collapsedRealm] = $this->splitName($member->name);
            if ($charName === null) {
                $errored++;
                continue;
            }

            // Reuse RIO's realm slug map. Blizzard's slug convention
            // (lowercase, hyphenated) matches RIO's for the realms we
            // care about, so a divergent map isn't worth the duplication
            // until a realm actually 404s here but works on RIO.
            if ($member->realm) {
                $slug = RealmSlug::slugifyCanonical($member->realm) ?? RealmSlug::slugify($collapsedRealm);
            } else {
                $slug = RealmSlug::slugify($collapsedRealm);
                if ($collapsedRealm !== null
                    && ! isset(((array) config('raiderio.realm_slugs', []))[$collapsedRealm])
                    && $slug === strtolower($collapsedRealm)) {
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
                    [
                        'url' => $url,
                        'headers' => $headers,
                        'query' => $query,
                    ] = $this->client->profileEndpoint($job['slug'], $job['name']);
                    $reqs[] = $pool
                        ->as((string) $memberId)
                        ->acceptJson()
                        ->timeout($timeout)
                        ->withHeaders($headers)
                        ->get($url, $query);
                }
                return $reqs;
            });

            foreach ($batch as $memberId => $job) {
                $resp = $responses[(string) $memberId] ?? null;

                if ($resp instanceof \Throwable) {
                    Log::warning('blizzard profile fetch failed', [
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
                    Log::warning('blizzard non-2xx', [
                        'member' => $job['member']->name, 'slug' => $job['slug'],
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

        if ($unknownRealms !== []) {
            Log::info('blizzard realms used lowercase fallback', [
                'realms' => array_keys($unknownRealms),
                'hint' => 'Add to config/raiderio.php realm_slugs map if any returned 404.',
            ]);
        }

        // Hash sorted by member id so payload order doesn't bust the
        // dedupe. Same payload across two pulls = same snapshot row.
        ksort($perMemberPayloads);
        $payloadHash = hash('sha256', json_encode($perMemberPayloads, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($perMemberPayloads, $payloadHash, $now, $matched, $missing, $errored, $members) {
            $snapshot = Snapshot::query()->firstOrCreate(
                [
                    'guild_key' => $this->guildKey,
                    'source' => Snapshot::SOURCE_BLIZZARD,
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
                    ]
                );
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
     * N members whose profile-summary is most stale: never-imported
     * first (NULL last_seen), then ordered by oldest captured_at.
     * Without a limit, returns every active member - same shape as
     * before. Stable secondary sort by member.id breaks ties.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Member>
     */
    private function selectMembersToFetch(): \Illuminate\Database\Eloquent\Collection
    {
        $latestPerMember = DB::table('member_snapshots as ms')
            ->select('ms.member_id', DB::raw('MAX(s.captured_at) as last_seen'))
            ->join('snapshots as s', 's.id', '=', 'ms.snapshot_id')
            ->where('s.source', Snapshot::SOURCE_BLIZZARD)
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
     * Pull equipped_item_level off the profile summary, gated on
     * recency: GRM must have seen the char online inside the window
     * AND Blizzard's last_login_timestamp must also fall inside it.
     * Mirrors the RIO importer's gate so behaviour is consistent
     * regardless of which source the roster ends up reading from.
     *
     * Reuses raiderio.stale_ilvl_window_days; the same duration
     * applies across all ilvl sources by design.
     *
     * @param  array<string,mixed>  $body
     */
    private function equippedIlvl(array $body, Member $member): ?int
    {
        $v = $body['equipped_item_level'] ?? null;
        if (! is_numeric($v) || $v <= 0) {
            return null;
        }
        if (! $this->ilvlSampleIsFresh($body, $member)) {
            return null;
        }
        return (int) round((float) $v);
    }

    /**
     * @param  array<string,mixed>  $body
     */
    private function ilvlSampleIsFresh(array $body, Member $member): bool
    {
        $windowDays = (int) (config('raiderio.stale_ilvl_window_days') ?? 0);
        if ($windowDays <= 0) {
            return true;
        }
        $cutoff = CarbonImmutable::now()->subDays($windowDays);

        $lastOnline = $member->last_online_at;
        if ($lastOnline === null || $lastOnline->lt($cutoff)) {
            return false;
        }

        // Blizzard ships last_login_timestamp as Unix milliseconds.
        // Note this represents last *logout* in practice (the API
        // updates on session end), which is exactly the freshness
        // signal we want for the cached gear blob.
        $lastLoginMs = $body['last_login_timestamp'] ?? null;
        if (! is_numeric($lastLoginMs) || $lastLoginMs <= 0) {
            return false;
        }
        $lastLogin = CarbonImmutable::createFromTimestampMs((int) $lastLoginMs);
        return $lastLogin->greaterThanOrEqualTo($cutoff);
    }
}
