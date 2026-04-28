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
 * Per-report deep import: pull the fight list + per-actor table for
 * every encounter in a WCL report and store it as wcl_fights +
 * wcl_actor_parses.
 *
 * One GraphQL query per report (uses an aliased query to fetch fights
 * + the DPS table + the HPS table in one round-trip). Reports that
 * have already been imported are skipped unless `force=true`.
 *
 * Trash + non-encounter pulls (encounter_id=0) are filtered out at
 * import time so the wcl_fights table is exclusively boss attempts.
 */
class WclFightImporter
{
    /**
     * Combined query: report metadata + fights list + DPS/HPS tables +
     * DPS/HPS rankings. Tables and rankings come back as JSON blobs;
     * the importer walks them to build wcl_actor_parses rows and then
     * fill parse_percentile + bracket_percentile from the rankings.
     *
     * Aliasing two `rankings(...)` calls into one query lets us pull
     * everything in a single GraphQL round-trip per report.
     */
    /**
     * Phase 1: fights + DPS/HPS tables. WCL requires table() to be scoped
     * by fightIDs or a startTime/endTime range, so we pass 0..99999999999
     * (≈ 3 years in ms relative to report start) to cover any raid log.
     *
     * Rankings cannot be fetched in this query: the rankings() field
     * rejects startTime/endTime and only accepts fightIDs, which we don't
     * know until the fights resolve. They come from PHASE_TWO_QUERY below.
     */
    private const REPORT_DEEP_QUERY = <<<'GQL'
    query DeepReport($code: String!) {
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
                damage:  table(dataType: DamageDone, startTime: 0, endTime: 99999999999)
                healing: table(dataType: Healing,    startTime: 0, endTime: 99999999999)
            }
        }
    }
    GQL;

    /**
     * Phase 2: per-actor parse rankings, scoped by the fight IDs we
     * resolved in phase 1. Best-effort: if this errors we still keep
     * the fights + tables already written.
     */
    private const RANKINGS_QUERY = <<<'GQL'
    query DeepRankings($code: String!, $fightIDs: [Int]!) {
        reportData {
            report(code: $code) {
                dpsRankings: rankings(playerMetric: dps, fightIDs: $fightIDs)
                hpsRankings: rankings(playerMetric: hps, fightIDs: $fightIDs)
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
     * @return array{fights_inserted:int, parses_inserted:int}
     */
    public function importOne(WclReport $report): array
    {
        $resp = $this->client->query(self::REPORT_DEEP_QUERY, ['code' => $report->code]);

        if ($resp->status() === 401) {
            $this->client->flushTokenCache();
            $resp = $this->client->query(self::REPORT_DEEP_QUERY, ['code' => $report->code]);
        }
        if (! $resp->successful()) {
            throw new \RuntimeException(sprintf(
                'WCL report %s returned %d: %s',
                $report->code, $resp->status(), mb_substr($resp->body(), 0, 200),
            ));
        }

        $body = $resp->json();
        if (isset($body['errors'])) {
            $first = $body['errors'][0]['message'] ?? 'unknown GraphQL error';
            throw new \RuntimeException("WCL report {$report->code}: {$first}");
        }

        $reportNode = $body['data']['reportData']['report'] ?? null;
        if (! is_array($reportNode)) {
            throw new \RuntimeException("WCL report {$report->code}: empty response");
        }

        $fights = is_array($reportNode['fights'] ?? null) ? $reportNode['fights'] : [];
        $damageTable = $this->extractActors($reportNode['damage'] ?? null);
        $healingTable = $this->extractActors($reportNode['healing'] ?? null);
        // Rankings are indexed by (fight_id, role, character_name) so the
        // post-write step can look up percentiles in O(1) per parse row.
        // Phase 2 query: rankings need fightIDs we just resolved.
        $rankingsByFight = $this->fetchRankings($report->code, $fights);
        $members = Member::query()->forGuild($this->guildKey)->get(['id', 'name']);

        $fightsInserted = 0;
        $parsesInserted = 0;
        $parsesRanked = 0;

        DB::transaction(function () use ($report, $fights, $damageTable, $healingTable, $rankingsByFight, $members, &$fightsInserted, &$parsesInserted, &$parsesRanked) {
            foreach ($fights as $f) {
                if (! is_array($f) || empty($f['id']) || (int) ($f['encounterID'] ?? 0) === 0) {
                    continue;
                }
                $fight = WclFight::query()->updateOrCreate(
                    ['wcl_report_id' => $report->id, 'fight_id' => (int) $f['id']],
                    [
                        'encounter_id' => (int) ($f['encounterID'] ?? 0),
                        'name' => (string) ($f['name'] ?? 'Unknown'),
                        'difficulty' => isset($f['difficulty']) ? (int) $f['difficulty'] : null,
                        'kill' => (bool) ($f['kill'] ?? false),
                        'best_percentage' => $this->bestPercentage($f),
                        'duration_ms' => isset($f['startTime'], $f['endTime']) ? max(0, (int) $f['endTime'] - (int) $f['startTime']) : null,
                        'start_time' => isset($f['startTime']) && $report->start_time
                            ? CarbonImmutable::createFromTimestampMs($report->start_time->timestamp * 1000 + (int) $f['startTime']) : null,
                        'end_time' => isset($f['endTime']) && $report->start_time
                            ? CarbonImmutable::createFromTimestampMs($report->start_time->timestamp * 1000 + (int) $f['endTime']) : null,
                        'raw_json' => $f,
                    ]
                );
                if ($fight->wasRecentlyCreated) $fightsInserted++;

                $rankingsForFight = $rankingsByFight[(int) $f['id']] ?? [];
                $parsesInserted += $this->writeParses($fight, $damageTable, WclActorParse::ROLE_DPS, $members, $rankingsForFight);
                $parsesInserted += $this->writeParses($fight, $healingTable, WclActorParse::ROLE_HEALER, $members, $rankingsForFight);
                $parsesRanked += $this->countRankedRows($rankingsForFight);
            }

            $report->forceFill(['fights_imported_at' => now()])->save();
        });

        return [
            'fights_inserted' => $fightsInserted,
            'parses_inserted' => $parsesInserted,
            'parses_ranked' => $parsesRanked,
        ];
    }

    /**
     * @param  array<string,mixed>  $tableActors  Already extracted actor list.
     * @param  EloquentCollection<int, Member>  $members
     * @param  array<string,array<string,array{rank:?int,bracket:?int}>>  $rankingsForFight
     *         Indexed by role -> lowercased actor name -> percentiles.
     */
    private function writeParses(
        WclFight $fight,
        array $tableActors,
        string $role,
        EloquentCollection $members,
        array $rankingsForFight = [],
    ): int {
        $inserted = 0;
        // Index member ids by lowercased "name" prefix for matching.
        $byName = $members->keyBy(fn ($m) => mb_strtolower(explode('-', $m->name, 2)[0]));
        $rankingsForRole = $rankingsForFight[$role] ?? [];

        foreach ($tableActors as $actor) {
            if (! is_array($actor) || empty($actor['name'])) {
                continue;
            }
            $name = (string) $actor['name'];
            $memberId = $byName->get(mb_strtolower($name))?->id;
            $ranking = $rankingsForRole[mb_strtolower($name)] ?? null;

            $parse = WclActorParse::query()->updateOrCreate(
                ['wcl_fight_id' => $fight->id, 'actor_name' => $name],
                [
                    'member_id' => $memberId,
                    'actor_class' => is_string($actor['type'] ?? null) ? $actor['type'] : null,
                    'actor_spec' => is_string($actor['icon'] ?? null) ? $actor['icon'] : null,
                    'role' => $role,
                    // WCL's table 'total' is the cumulative damage/healing.
                    // Per-second derived from fight duration when available.
                    'metric_per_second' => $this->perSecond($actor, $fight->duration_ms),
                    'parse_percentile' => $ranking['rank'] ?? null,
                    'bracket_percentile' => $ranking['bracket'] ?? null,
                    'item_level' => isset($actor['itemLevel']) ? (int) $actor['itemLevel'] : null,
                    'raw_json' => $actor,
                ]
            );
            if ($parse->wasRecentlyCreated) $inserted++;
        }

        return $inserted;
    }

    /**
     * Walk the dpsRankings + hpsRankings JSON blobs and produce a lookup
     * indexed by (fight_id) -> (role) -> (lowercased name) -> percentiles.
     *
     * The WCL rankings shape per playerMetric is:
     *   { data: [
     *       { fightID: int,
     *         roles: {
     *           dps:     { characters: [{name, rankPercent, bracketPercent, ...}, ...] },
     *           healers: { characters: [...] },
     *           tanks:   { characters: [...] }
     *         }
     *       }
     *   ] }
     *
     * @return array<int, array<string, array<string, array{rank:?int,bracket:?int}>>>
     */
    /**
     * Phase 2: query WCL for rankings scoped to the fight IDs resolved
     * in phase 1. Best-effort - if this errors out we return [] so the
     * fights + tables still get written, just without parse percentiles.
     *
     * @param  list<array<string,mixed>>  $fights  Phase-1 fight nodes.
     * @return array<int, array<string, array<string, array{rank:?int,bracket:?int}>>>
     */
    private function fetchRankings(string $code, array $fights): array
    {
        $fightIds = [];
        foreach ($fights as $f) {
            if (is_array($f) && ! empty($f['id']) && (int) ($f['encounterID'] ?? 0) !== 0) {
                $fightIds[] = (int) $f['id'];
            }
        }
        if ($fightIds === []) return [];

        try {
            $resp = $this->client->query(self::RANKINGS_QUERY, [
                'code' => $code, 'fightIDs' => $fightIds,
            ]);
            if ($resp->status() === 401) {
                $this->client->flushTokenCache();
                $resp = $this->client->query(self::RANKINGS_QUERY, [
                    'code' => $code, 'fightIDs' => $fightIds,
                ]);
            }
            if (! $resp->successful()) {
                Log::warning('WclFightImporter: rankings query failed', [
                    'code' => $code, 'status' => $resp->status(),
                ]);
                return [];
            }
            $body = $resp->json();
            if (isset($body['errors'])) {
                Log::warning('WclFightImporter: rankings GraphQL error', [
                    'code' => $code, 'message' => $body['errors'][0]['message'] ?? 'unknown',
                ]);
                return [];
            }
            $node = $body['data']['reportData']['report'] ?? null;
            if (! is_array($node)) return [];

            return $this->indexRankings($node['dpsRankings'] ?? null, $node['hpsRankings'] ?? null);
        } catch (\Throwable $e) {
            Log::warning('WclFightImporter: rankings fetch threw', [
                'code' => $code, 'message' => $e->getMessage(),
            ]);
            return [];
        }
    }

    private function indexRankings(mixed $dpsJson, mixed $hpsJson): array
    {
        $out = [];
        foreach ([
            ['blob' => $dpsJson, 'role' => WclActorParse::ROLE_DPS,    'pickRole' => 'dps'],
            ['blob' => $hpsJson, 'role' => WclActorParse::ROLE_HEALER, 'pickRole' => 'healers'],
        ] as $source) {
            $decoded = $this->decodeMaybeJson($source['blob']);
            $entries = $decoded['data'] ?? [];
            if (! is_array($entries)) continue;

            foreach ($entries as $entry) {
                if (! is_array($entry) || ! isset($entry['fightID'])) continue;
                $fightId = (int) $entry['fightID'];
                $roleNode = $entry['roles'][$source['pickRole']] ?? null;
                $characters = is_array($roleNode['characters'] ?? null) ? $roleNode['characters'] : [];
                foreach ($characters as $c) {
                    if (! is_array($c) || empty($c['name'])) continue;
                    $key = mb_strtolower((string) $c['name']);
                    $out[$fightId][$source['role']][$key] = [
                        'rank' => isset($c['rankPercent']) ? (int) round((float) $c['rankPercent']) : null,
                        'bracket' => isset($c['bracketPercent']) ? (int) round((float) $c['bracketPercent']) : null,
                    ];
                }
            }
        }
        return $out;
    }

    /**
     * @param  array<string,array<string,array{rank:?int,bracket:?int}>>  $rankingsForFight
     */
    private function countRankedRows(array $rankingsForFight): int
    {
        $n = 0;
        foreach ($rankingsForFight as $byName) {
            foreach ($byName as $row) {
                if (($row['rank'] ?? null) !== null) $n++;
            }
        }
        return $n;
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
            // Some clients return an already-decoded array.
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
        // for older WCL responses.
        foreach (['fightPercentage', 'bossPercentage'] as $k) {
            $v = $f[$k] ?? null;
            if (is_numeric($v)) {
                // WCL stores percentages as ints scaled by 100 (e.g. 3025 = 30.25%).
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
