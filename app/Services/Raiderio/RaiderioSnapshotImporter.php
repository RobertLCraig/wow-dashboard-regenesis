<?php

namespace App\Services\Raiderio;

use App\Models\Member;
use App\Models\MemberSnapshot;
use App\Models\Snapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pull a fresh Raider.IO snapshot for every active member in the local
 * roster. Unlike the wowaudit importer (which is bounded by the team
 * roster on wowaudit's side), this is roster-flexible: any character
 * GRM has marked active gets queried. That fits the heroic team's looser
 * roster - you don't have to add anyone to a "team" anywhere first.
 *
 * One HTTP call per member. With ~100 members and a 100ms pace this is
 * ~10s of wall time and well under Raider.IO's unwritten ~600/min limit.
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
        private readonly int $requestDelayMs = 100,
        private readonly int $minLevel = 70,
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
        $members = Member::query()
            ->forGuild($this->guildKey)
            ->active()
            ->where('level', '>=', $this->minLevel)
            ->orderBy('id')
            ->get();

        $now = CarbonImmutable::now();
        $perMemberPayloads = [];
        $matched = 0;
        $missing = 0;
        $errored = 0;
        $unknownRealms = [];

        foreach ($members as $member) {
            [$charName, $collapsedRealm] = $this->splitName($member->name);
            if ($charName === null) {
                $errored++;
                continue;
            }

            $slug = RealmSlug::slugify($collapsedRealm);
            if ($collapsedRealm !== null && ! isset(((array) config('raiderio.realm_slugs', []))[$collapsedRealm]) && $slug === strtolower($collapsedRealm)) {
                // Track unknown realms once per run so officers know what
                // to add to config('raiderio.realm_slugs') without spamming
                // the log per-character.
                $unknownRealms[$collapsedRealm] = true;
            }

            try {
                $resp = $this->client->profile($slug, $charName);
            } catch (\Throwable $e) {
                Log::warning('raiderio profile fetch failed', [
                    'member' => $member->name, 'slug' => $slug, 'message' => $e->getMessage(),
                ]);
                $errored++;
                $this->sleep();
                continue;
            }

            if ($resp->status() === 404) {
                // Char doesn't exist on RIO (low level, recently renamed,
                // wrong realm slug). Skipping is the right move; we'll
                // pick them up next time if they appear.
                $missing++;
                $this->sleep();
                continue;
            }

            if (! $resp->successful()) {
                Log::warning('raiderio non-2xx', [
                    'member' => $member->name, 'slug' => $slug,
                    'status' => $resp->status(), 'body' => mb_substr($resp->body(), 0, 200),
                ]);
                $errored++;
                $this->sleep();
                continue;
            }

            $body = $resp->json();
            if (! is_array($body)) {
                $errored++;
                $this->sleep();
                continue;
            }

            $perMemberPayloads[$member->id] = $body;
            $matched++;
            $this->sleep();
        }

        if ($unknownRealms !== []) {
            Log::info('raiderio realms used lowercase fallback', [
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
                        'ilvl' => $this->equippedIlvl($body),
                        'raid_progression_json' => $body['raid_progression'] ?? null,
                        'mplus_score' => $this->currentSeasonScore($body),
                        'mplus_keystone' => $this->highestWeeklyKeystone($body),
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

    private function sleep(): void
    {
        if ($this->requestDelayMs > 0) {
            usleep($this->requestDelayMs * 1000);
        }
    }

    /**
     * Raider.IO returns item_level_equipped as a float; we store ilvl as
     * an int (matches the wowaudit pathway). Round to nearest, not floor,
     * so 642.7 -> 643.
     *
     * @param  array<string,mixed>  $body
     */
    private function equippedIlvl(array $body): ?int
    {
        $v = $body['gear']['item_level_equipped'] ?? null;
        if (! is_numeric($v) || $v <= 0) {
            return null;
        }
        return (int) round((float) $v);
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
}
