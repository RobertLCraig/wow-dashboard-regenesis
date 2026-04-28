<?php

namespace App\Services\Wcl;

use App\Models\Member;
use App\Models\WclActorParse;
use App\Models\WclFight;
use App\Models\WclReport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Per-report deep import. Fetches the fight list for a report, then
 * for every encounter pulls the DPS/healing tables + rankings scoped
 * to that single fight ID and writes one wcl_actor_parses row per
 * player per fight, role assigned from rankings (or table magnitude
 * when the pull hasn't been ranked yet).
 *
 * Why per-fight: WCL's table() returns total damage/healing across
 * the requested time range, so a report-wide query can't tell you a
 * player's per-fight numbers. Scoping by fightIDs gives accurate
 * per-pull metrics, and the per-fight role bucket comes from
 * rankings.roles (dps / healers / tanks) so we don't have to guess.
 *
 * Trash + non-encounter pulls (encounter_id = 0) are filtered out at
 * import time so wcl_fights stays exclusively boss attempts.
 */
class WclFightImporter
{
    /**
     * Phase 1: report metadata + fight list. No tables or rankings here -
     * those need fight IDs we don't have until this resolves.
     */
    private const FIGHT_LIST_QUERY = <<<'GQL'
    query FightList($code: String!) {
        reportData {
            report(code: $code) {
                code
                title
                fights(translate: true) {
                    id
                    encounterID
                    name
                    difficulty
                    kill
                    fightPercentage
                    bossPercentage
                    startTime
                    endTime
                }
            }
        }
    }
    GQL;

    /**
     * Phase 2: per-fight damage + healing tables + DPS/HPS rankings,
     * scoped to a single fight ID. Aliasing four resolvers in one
     * GraphQL request keeps it to one round-trip per pull.
     */
    private const PER_FIGHT_QUERY = <<<'GQL'
    query FightDeep($code: String!, $fightId: Int!) {
        reportData {
            report(code: $code) {
                damage:      table(dataType: DamageDone, fightIDs: [$fightId])
                healing:     table(dataType: Healing,    fightIDs: [$fightId])
                dpsRankings: rankings(playerMetric: dps, fightIDs: [$fightId])
                hpsRankings: rankings(playerMetric: hps, fightIDs: [$fightId])
            }
        }
    }
    GQL;

    public function __construct(
        private readonly WclClient $client,
        private readonly string $guildKey,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            client: WclClient::fromConfig(),
            guildKey: (string) config('grm.guild_key'),
        );
    }

    /**
     * Backfill fights + parses for every report that doesn't yet have
     * fights_imported_at set. Caller controls the cap so a single
     * sync doesn't accidentally torch the WCL rate limit.
     *
     * @return array{
     *   reports_processed:int, fights_inserted:int, parses_inserted:int,
     *   skipped_already_imported:int, errored:int
     * }
     */
    public function backfillUnimported(int $maxReports = 5, bool $force = false): array
    {
        $query = WclReport::query()
            ->where('guild_key', $this->guildKey)
            ->orderByDesc('start_time')
            ->limit($maxReports);
        if (! $force) {
            $query->whereNull('fights_imported_at');
        }

        $reports = $query->get();
        $stats = [
            'reports_processed' => 0,
            'fights_inserted' => 0,
            'parses_inserted' => 0,
            'parses_ranked' => 0,
            'skipped_already_imported' => 0,
            'errored' => 0,
        ];

        foreach ($reports as $report) {
            try {
                $r = $this->importOne($report);
                $stats['reports_processed']++;
                $stats['fights_inserted'] += $r['fights_inserted'];
                $stats['parses_inserted'] += $r['parses_inserted'];
                $stats['parses_ranked'] += $r['parses_ranked'] ?? 0;
            } catch (\Throwable $e) {
                Log::warning('WclFightImporter: report import failed', [
                    'code' => $report->code, 'message' => $e->getMessage(),
                ]);
                $stats['errored']++;
            }
        }

        return $stats;
    }

    /**
     * Force-import one report, regardless of fights_imported_at.
     *
     * @return array{fights_inserted:int, parses_inserted:int, parses_ranked:int}
     */
    public function importOne(WclReport $report): array
    {
        $reportNode = $this->fetchFightList($report->code);
        $fights = is_array($reportNode['fights'] ?? null) ? $reportNode['fights'] : [];

        $members = Member::query()->forGuild($this->guildKey)->get(['id', 'name']);

        $fightsInserted = 0;
        $parsesInserted = 0;
        $parsesRanked = 0;

        // Per-fight loop. Each iteration is one GraphQL request to WCL
        // and one row-write transaction. We don't wrap the whole report
        // in a single transaction so that a mid-import network blip
        // doesn't roll back fights that already wrote successfully.
        foreach ($fights as $f) {
            if (! is_array($f) || empty($f['id']) || (int) ($f['encounterID'] ?? 0) === 0) {
                continue;
            }
            $fightRow = $this->upsertFight($report, $f);
            if ($fightRow->wasRecentlyCreated) $fightsInserted++;

            $deep = $this->fetchPerFight($report->code, (int) $f['id']);
            if ($deep === null) continue;

            $damage = $this->extractActors($deep['damage'] ?? null);
            $healing = $this->extractActors($deep['healing'] ?? null);
            $rankings = $this->indexRankingsForOneFight(
                $deep['dpsRankings'] ?? null,
                $deep['hpsRankings'] ?? null,
            );

            $written = $this->writeParsesForFight($fightRow, $damage, $healing, $rankings, $members);
            $parsesInserted += $written['inserted'];
            $parsesRanked += $written['ranked'];
        }

        $report->forceFill(['fights_imported_at' => now()])->save();

        return [
            'fights_inserted' => $fightsInserted,
            'parses_inserted' => $parsesInserted,
            'parses_ranked' => $parsesRanked,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchFightList(string $code): array
    {
        $resp = $this->client->query(self::FIGHT_LIST_QUERY, ['code' => $code]);
        if ($resp->status() === 401) {
            $this->client->flushTokenCache();
            $resp = $this->client->query(self::FIGHT_LIST_QUERY, ['code' => $code]);
        }
        if (! $resp->successful()) {
            throw new \RuntimeException(sprintf(
                'WCL report %s returned %d: %s',
                $code, $resp->status(), mb_substr($resp->body(), 0, 200),
            ));
        }
        $body = $resp->json();
        if (isset($body['errors'])) {
            throw new \RuntimeException(
                "WCL report {$code}: " . ($body['errors'][0]['message'] ?? 'unknown GraphQL error')
            );
        }
        $node = $body['data']['reportData']['report'] ?? null;
        if (! is_array($node)) {
            throw new \RuntimeException("WCL report {$code}: empty response");
        }
        return $node;
    }

    /**
     * @return array<string, mixed>|null  null on error - caller skips this fight.
     */
    private function fetchPerFight(string $code, int $fightId): ?array
    {
        try {
            $resp = $this->client->query(self::PER_FIGHT_QUERY, [
                'code' => $code, 'fightId' => $fightId,
            ]);
            if ($resp->status() === 401) {
                $this->client->flushTokenCache();
                $resp = $this->client->query(self::PER_FIGHT_QUERY, [
                    'code' => $code, 'fightId' => $fightId,
                ]);
            }
            if (! $resp->successful()) {
                Log::warning('WclFightImporter: per-fight query failed', [
                    'code' => $code, 'fight_id' => $fightId, 'status' => $resp->status(),
                ]);
                return null;
            }
            $body = $resp->json();
            if (isset($body['errors'])) {
                Log::warning('WclFightImporter: per-fight GraphQL error', [
                    'code' => $code, 'fight_id' => $fightId,
                    'message' => $body['errors'][0]['message'] ?? 'unknown',
                ]);
                return null;
            }
            $node = $body['data']['reportData']['report'] ?? null;
            return is_array($node) ? $node : null;
        } catch (\Throwable $e) {
            Log::warning('WclFightImporter: per-fight fetch threw', [
                'code' => $code, 'fight_id' => $fightId, 'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $f  WCL fight node.
     */
    private function upsertFight(WclReport $report, array $f): WclFight
    {
        return WclFight::query()->updateOrCreate(
            ['wcl_report_id' => $report->id, 'fight_id' => (int) $f['id']],
            [
                'encounter_id' => (int) ($f['encounterID'] ?? 0),
                'name' => (string) ($f['name'] ?? 'Unknown'),
                'difficulty' => isset($f['difficulty']) ? (int) $f['difficulty'] : null,
                'kill' => (bool) ($f['kill'] ?? false),
                'best_percentage' => $this->bestPercentage($f),
                'duration_ms' => isset($f['startTime'], $f['endTime'])
                    ? max(0, (int) $f['endTime'] - (int) $f['startTime'])
                    : null,
                'start_time' => isset($f['startTime']) && $report->start_time
                    ? CarbonImmutable::createFromTimestampMs($report->start_time->timestamp * 1000 + (int) $f['startTime'])
                    : null,
                'end_time' => isset($f['endTime']) && $report->start_time
                    ? CarbonImmutable::createFromTimestampMs($report->start_time->timestamp * 1000 + (int) $f['endTime'])
                    : null,
                'raw_json' => $f,
            ]
        );
    }

    /**
     * Write one wcl_actor_parses row per unique player in the fight,
     * with the right role and metric. Players appear in both tables
     * (e.g. healers do off-damage, DPS do self-healing) so we union
     * the names, decide a role per player, and then read the matching
     * table row to compute metric_per_second.
     *
     * Role priority:
     *   1. WCL rankings - dps / healers / tanks bucket (authoritative).
     *   2. Magnitude fallback - whichever table has the higher per-second
     *      value wins. Used when rankings haven't been computed yet
     *      (fresh logs / very short pulls).
     *
     * @param  list<array<string,mixed>>  $damage
     * @param  list<array<string,mixed>>  $healing
     * @param  array{
     *     by_role: array<string, array<string, array{rank:?int,bracket:?int}>>,
     *     role_for: array<string, string>,
     *     ranked_count: int
     *   }  $rankings
     * @param  EloquentCollection<int, Member>  $members
     * @return array{inserted:int, ranked:int}
     */
    private function writeParsesForFight(
        WclFight $fight,
        array $damage,
        array $healing,
        array $rankings,
        EloquentCollection $members,
    ): array {
        $byNameMember = $members->keyBy(fn ($m) => mb_strtolower(explode('-', $m->name, 2)[0]));

        $damageByName = [];
        foreach ($damage as $a) {
            if (is_array($a) && ! empty($a['name'])) {
                $damageByName[mb_strtolower((string) $a['name'])] = $a;
            }
        }
        $healingByName = [];
        foreach ($healing as $a) {
            if (is_array($a) && ! empty($a['name'])) {
                $healingByName[mb_strtolower((string) $a['name'])] = $a;
            }
        }

        $allNames = array_unique(array_merge(array_keys($damageByName), array_keys($healingByName)));
        $inserted = 0;

        DB::transaction(function () use (
            $fight, $damageByName, $healingByName, $allNames,
            $rankings, $byNameMember, &$inserted,
        ) {
            foreach ($allNames as $lowered) {
                $damageActor = $damageByName[$lowered] ?? null;
                $healingActor = $healingByName[$lowered] ?? null;
                $name = (string) (($damageActor['name'] ?? null) ?? ($healingActor['name'] ?? ''));
                if ($name === '') continue;

                $role = $this->resolveRole(
                    $lowered, $damageActor, $healingActor, $fight->duration_ms, $rankings['role_for'],
                );

                // Pick the table row that matches the resolved role; tanks
                // use the damage table since we don't track healing-as-tank.
                $primary = match ($role) {
                    WclActorParse::ROLE_HEALER => $healingActor ?? $damageActor,
                    default                     => $damageActor ?? $healingActor,
                };
                if (! is_array($primary)) continue;

                $rankingRow = $rankings['by_role'][$role][$lowered] ?? null;

                $parse = WclActorParse::query()->updateOrCreate(
                    ['wcl_fight_id' => $fight->id, 'actor_name' => $name],
                    [
                        'member_id' => $byNameMember->get($lowered)?->id,
                        'actor_class' => is_string($primary['type'] ?? null) ? $primary['type'] : null,
                        'actor_spec' => is_string($primary['icon'] ?? null) ? $primary['icon'] : null,
                        'role' => $role,
                        'metric_per_second' => $this->perSecond($primary, $fight->duration_ms),
                        'parse_percentile' => $rankingRow['rank'] ?? null,
                        'bracket_percentile' => $rankingRow['bracket'] ?? null,
                        'item_level' => isset($primary['itemLevel']) ? (int) $primary['itemLevel'] : null,
                        'raw_json' => $primary,
                    ]
                );
                if ($parse->wasRecentlyCreated) $inserted++;
            }
        });

        return ['inserted' => $inserted, 'ranked' => $rankings['ranked_count']];
    }

    /**
     * Roles each WoW class can actually fill. Used to constrain the
     * magnitude-fallback role detection so a Mage with a strong
     * absorb-shield pull doesn't get mis-tagged as a healer just
     * because their hps line was higher than their dps line.
     *
     * Pure DPS classes are dps-only; hybrid healers and tanks list
     * the extra roles they can fill. Anything not in this map (NPCs,
     * pets, bosses) is treated as unconstrained.
     *
     * @var array<string, list<string>>
     */
    private const CLASS_ROLES = [
        'Druid'       => [WclActorParse::ROLE_DPS, WclActorParse::ROLE_HEALER, WclActorParse::ROLE_TANK],
        'Paladin'     => [WclActorParse::ROLE_DPS, WclActorParse::ROLE_HEALER, WclActorParse::ROLE_TANK],
        'Monk'        => [WclActorParse::ROLE_DPS, WclActorParse::ROLE_HEALER, WclActorParse::ROLE_TANK],
        'Priest'      => [WclActorParse::ROLE_DPS, WclActorParse::ROLE_HEALER],
        'Shaman'      => [WclActorParse::ROLE_DPS, WclActorParse::ROLE_HEALER],
        'Evoker'      => [WclActorParse::ROLE_DPS, WclActorParse::ROLE_HEALER],
        'DeathKnight' => [WclActorParse::ROLE_DPS, WclActorParse::ROLE_TANK],
        'Warrior'     => [WclActorParse::ROLE_DPS, WclActorParse::ROLE_TANK],
        'DemonHunter' => [WclActorParse::ROLE_DPS, WclActorParse::ROLE_TANK],
        'Mage'        => [WclActorParse::ROLE_DPS],
        'Warlock'     => [WclActorParse::ROLE_DPS],
        'Rogue'       => [WclActorParse::ROLE_DPS],
        'Hunter'      => [WclActorParse::ROLE_DPS],
    ];

    /**
     * Pick the role for one actor. Rankings are authoritative when the
     * player appears in them; otherwise compare per-second magnitudes
     * across the damage/healing tables, but constrained by what the
     * player's class is actually capable of (a Mage cannot be a healer
     * no matter what their HPS line says on a short pull).
     *
     * @param  array<string,mixed>|null  $damageActor
     * @param  array<string,mixed>|null  $healingActor
     * @param  array<string, string>     $rankingRoleFor  lower-name => role
     */
    private function resolveRole(
        string $loweredName,
        ?array $damageActor,
        ?array $healingActor,
        ?int $durationMs,
        array $rankingRoleFor,
    ): string {
        if (isset($rankingRoleFor[$loweredName])) {
            return $rankingRoleFor[$loweredName];
        }

        $class = (string) (($damageActor['type'] ?? null) ?? ($healingActor['type'] ?? ''));
        $allowed = self::CLASS_ROLES[$class] ?? null;

        $dps = $damageActor ? ($this->perSecond($damageActor, $durationMs) ?? 0) : 0;
        $hps = $healingActor ? ($this->perSecond($healingActor, $durationMs) ?? 0) : 0;
        $magnitudeRole = $hps > $dps && $hps > 0
            ? WclActorParse::ROLE_HEALER
            : WclActorParse::ROLE_DPS;

        if ($allowed === null) {
            return $magnitudeRole;
        }
        if (in_array($magnitudeRole, $allowed, true)) {
            return $magnitudeRole;
        }
        // Magnitude said a role the class can't fill - fall back to the
        // first role the class CAN fill, which is always dps in the map.
        return $allowed[0];
    }

    /**
     * Walk a single fight's dpsRankings + hpsRankings JSON blobs and
     * produce a lookup keyed first by role -> lowercased name -> percentiles,
     * plus a flat name -> role index for fast role resolution.
     *
     * Tank rankings come from the dpsRankings query (their bucket lives
     * under roles.tanks alongside dps).
     *
     * @return array{
     *   by_role: array<string, array<string, array{rank:?int,bracket:?int}>>,
     *   role_for: array<string, string>,
     *   ranked_count: int
     * }
     */
    private function indexRankingsForOneFight(mixed $dpsJson, mixed $hpsJson): array
    {
        $byRole = [
            WclActorParse::ROLE_DPS => [],
            WclActorParse::ROLE_HEALER => [],
            WclActorParse::ROLE_TANK => [],
        ];
        $roleFor = [];
        $ranked = 0;

        // dpsRankings.roles contains BOTH the dps bucket and the tanks bucket.
        // hpsRankings.roles contains the healers bucket.
        $sources = [
            ['blob' => $dpsJson, 'role' => WclActorParse::ROLE_DPS,    'pickRole' => 'dps'],
            ['blob' => $dpsJson, 'role' => WclActorParse::ROLE_TANK,   'pickRole' => 'tanks'],
            ['blob' => $hpsJson, 'role' => WclActorParse::ROLE_HEALER, 'pickRole' => 'healers'],
        ];

        foreach ($sources as $source) {
            $decoded = $this->decodeMaybeJson($source['blob']);
            $entries = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];

            foreach ($entries as $entry) {
                if (! is_array($entry)) continue;
                $roleNode = $entry['roles'][$source['pickRole']] ?? null;
                $characters = is_array($roleNode['characters'] ?? null) ? $roleNode['characters'] : [];
                foreach ($characters as $c) {
                    if (! is_array($c) || empty($c['name'])) continue;
                    $key = mb_strtolower((string) $c['name']);
                    $rank = isset($c['rankPercent']) ? (int) round((float) $c['rankPercent']) : null;
                    $bracket = isset($c['bracketPercent']) ? (int) round((float) $c['bracketPercent']) : null;
                    $byRole[$source['role']][$key] = ['rank' => $rank, 'bracket' => $bracket];
                    $roleFor[$key] = $source['role'];
                    if ($rank !== null) $ranked++;
                }
            }
        }

        return ['by_role' => $byRole, 'role_for' => $roleFor, 'ranked_count' => $ranked];
    }

    private function decodeMaybeJson(mixed $blob): array
    {
        if (is_array($blob)) return $blob;
        if (! is_string($blob)) return [];
        try {
            return json_decode($blob, true, 512, JSON_THROW_ON_ERROR) ?: [];
        } catch (\JsonException) {
            return [];
        }
    }

    /**
     * WCL's `table()` returns a JSON string in the GraphQL response
     * (because the shape varies by dataType). Decode it and return the
     * `entries` array of actors.
     *
     * @return list<array<string,mixed>>
     */
    private function extractActors(mixed $tableJson): array
    {
        if (! is_string($tableJson)) {
            $decoded = is_array($tableJson) ? $tableJson : null;
        } else {
            try {
                $decoded = json_decode($tableJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return [];
            }
        }
        $entries = $decoded['data']['entries'] ?? $decoded['entries'] ?? [];
        return is_array($entries) ? array_values($entries) : [];
    }

    /**
     * @param  array<string,mixed>  $f
     */
    private function bestPercentage(array $f): ?float
    {
        // Prefer fightPercentage (boss HP %), fall back to bossPercentage
        // for older WCL responses. WCL stores percentages as ints scaled
        // by 100 (e.g. 3025 = 30.25%).
        foreach (['fightPercentage', 'bossPercentage'] as $k) {
            $v = $f[$k] ?? null;
            if (is_numeric($v)) {
                return round(((float) $v) / 100, 2);
            }
        }
        return null;
    }

    /**
     * @param  array<string,mixed>  $actor
     */
    private function perSecond(array $actor, ?int $durationMs): ?float
    {
        $total = $actor['total'] ?? null;
        if (! is_numeric($total) || ! $durationMs || $durationMs <= 0) {
            return null;
        }
        return round((float) $total / ($durationMs / 1000), 1);
    }
}
