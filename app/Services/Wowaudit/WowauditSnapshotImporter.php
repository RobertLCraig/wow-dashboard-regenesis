<?php

namespace App\Services\Wowaudit;

use App\Models\Member;
use App\Models\MemberSnapshot;
use App\Models\Snapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pulls the current period's wowaudit data and writes it into our
 * normalized schema as a fresh `snapshots` row with source='wowaudit'.
 * Per-character details land in `member_snapshots` rows pointing at
 * the matched `members.name` (Char-Realm).
 *
 * Wowaudit only tracks characters explicitly added to a team there;
 * GRM tracks every guild member. Mismatches (a wowaudit char with no
 * GRM member, or vice versa) are normal and logged at debug level.
 */
class WowauditSnapshotImporter
{
    public function __construct(
        private readonly WowauditClient $client,
        private readonly string $guildKey,
    ) {}

    /**
     * @return array{
     *   snapshot_id:int,
     *   period:?int,
     *   matched:int,
     *   skipped:int,
     *   characters_returned:int,
     * }
     */
    public function pullCurrentPeriod(): array
    {
        if (! $this->client->isConfigured()) {
            return ['snapshot_id' => 0, 'period' => null, 'matched' => 0, 'skipped' => 0, 'characters_returned' => 0];
        }

        $periodResp = $this->client->period();
        if (! $periodResp->successful()) {
            throw new \RuntimeException("wowaudit /period returned {$periodResp->status()}");
        }
        $period = (int) ($periodResp->json('current_period') ?? 0);
        if ($period <= 0) {
            throw new \RuntimeException('wowaudit /period missing current_period');
        }

        $histResp = $this->client->historicalDataForPeriod($period);
        if (! $histResp->successful()) {
            throw new \RuntimeException("wowaudit /historical_data?period=$period returned {$histResp->status()}");
        }
        $characters = $histResp->json('characters', []);
        if (! is_array($characters)) {
            $characters = [];
        }

        $charsResp = $this->client->characters();
        $rosterByName = collect(is_array($charsResp->json()) ? $charsResp->json() : [])
            ->keyBy(fn ($row) => $this->charKey($row['name'] ?? '', $row['realm'] ?? ''));

        $payloadHash = hash('sha256', json_encode([$period, $characters], JSON_THROW_ON_ERROR));
        $now = CarbonImmutable::now();

        return DB::transaction(function () use ($characters, $rosterByName, $payloadHash, $period, $now) {
            $snapshot = Snapshot::query()->firstOrCreate(
                [
                    'guild_key' => $this->guildKey,
                    'source' => Snapshot::SOURCE_WOWAUDIT,
                    'payload_hash' => $payloadHash,
                ],
                [
                    'captured_at' => $now,
                    'member_count' => count($characters),
                ]
            );

            // Even if dedupe matched (same payload as last pull), we still
            // re-attach member_snapshots so a roster change between pulls
            // gets reflected. Cost is small (one row per tracked char).
            $matched = 0;
            $skipped = 0;
            foreach ($characters as $char) {
                if (! is_array($char)) {
                    continue;
                }
                $name = $char['name'] ?? null;
                $realm = $char['realm'] ?? null;
                if (! $name || ! $realm) {
                    $skipped++;
                    continue;
                }
                $member = $this->matchMember($name, $realm);
                if (! $member) {
                    Log::debug('wowaudit char with no GRM match', ['name' => $name, 'realm' => $realm]);
                    $skipped++;
                    continue;
                }

                $rosterRow = $rosterByName->get($this->charKey($name, $realm));
                $bestGear = $this->bestGearFor($char['id'] ?? null);

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
                        'raw_json' => array_merge($char, $rosterRow ? ['_roster' => $rosterRow] : [], $bestGear ? ['_best_gear' => $bestGear] : []),
                        'ilvl' => $bestGear ? $this->equippedIlvl($bestGear) : null,
                        'vault_progress_json' => $char['data']['vault_options'] ?? null,
                        'mplus_keystone' => $this->highestKeystone($char['data']['dungeons_done'] ?? []),
                    ]
                );
                $matched++;
            }

            return [
                'snapshot_id' => $snapshot->id,
                'period' => $period,
                'matched' => $matched,
                'skipped' => $skipped,
                'characters_returned' => count($characters),
            ];
        });
    }

    /**
     * Match a wowaudit character to a `members` row. Wowaudit stores
     * realm with spaces and apostrophes (e.g. "Twisting Nether") whereas
     * GRM concatenates them ("TwistingNether"). Normalise both before
     * comparing.
     */
    private function matchMember(string $name, string $realm): ?Member
    {
        $normalised = $this->charKey($name, $realm);
        return Member::query()
            ->forGuild($this->guildKey)
            ->whereRaw("lower(replace(replace(name, ' ', ''), \"'\", '')) = ?", [$normalised])
            ->first();
    }

    private function charKey(string $name, string $realm): string
    {
        $clean = static fn (string $s) => str_replace([' ', "'"], '', mb_strtolower($s));
        return $clean($name) . '-' . $clean($realm);
    }

    /**
     * @param  array<int, array{level:int, dungeon:int}>  $dungeons
     */
    private function highestKeystone(array $dungeons): ?int
    {
        if (empty($dungeons)) {
            return null;
        }
        $levels = array_filter(array_map(fn ($d) => is_array($d) ? ($d['level'] ?? null) : null, $dungeons));
        return $levels ? max($levels) : null;
    }

    /**
     * Pull best_gear from /historical_data/{id} once per character. Cached
     * via $this->gearCache so repeated calls in one importer run hit the
     * API at most once per character.
     */
    private array $gearCache = [];

    /**
     * @return array<string, array{ilvl:int, id:int, name:string, quality:int}>|null
     */
    private function bestGearFor(?int $characterId): ?array
    {
        if (! $characterId) {
            return null;
        }
        if (array_key_exists($characterId, $this->gearCache)) {
            return $this->gearCache[$characterId];
        }
        $resp = $this->client->characterHistory($characterId);
        if (! $resp->successful()) {
            $this->gearCache[$characterId] = null;
            return null;
        }
        $gear = $resp->json('best_gear');
        return $this->gearCache[$characterId] = is_array($gear) ? $gear : null;
    }

    /**
     * @param  array<string, array{ilvl:int}>  $bestGear
     */
    private function equippedIlvl(array $bestGear): ?int
    {
        $slots = (array) config('wowaudit.gear_slots', []);
        $ilvls = [];
        foreach ($slots as $slot) {
            $v = $bestGear[$slot]['ilvl'] ?? null;
            if (is_int($v) && $v > 0) {
                $ilvls[] = $v;
            }
        }
        if (empty($ilvls)) {
            return null;
        }
        return (int) round(array_sum($ilvls) / count($ilvls));
    }
}
