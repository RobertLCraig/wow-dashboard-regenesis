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
     * Combined query: report metadata + fights list + DPS/HPS tables.
     * The two tables come back as JSON blobs that contain per-actor
     * arrays we walk to build wcl_actor_parses rows.
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
                damage: table(dataType: DamageDone)
                healing: table(dataType: Healing)
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
            'skipped_already_imported' => 0,
            'errored' => 0,
        ];

        foreach ($reports as $report) {
            try {
                $r = $this->importOne($report);
                $stats['reports_processed']++;
                $stats['fights_inserted'] += $r['fights_inserted'];
                $stats['parses_inserted'] += $r['parses_inserted'];
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
        $members = Member::query()->forGuild($this->guildKey)->get(['id', 'name']);

        $fightsInserted = 0;
        $parsesInserted = 0;

        DB::transaction(function () use ($report, $fights, $damageTable, $healingTable, $members, &$fightsInserted, &$parsesInserted) {
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

                $parsesInserted += $this->writeParses($fight, $damageTable, WclActorParse::ROLE_DPS, $members);
                $parsesInserted += $this->writeParses($fight, $healingTable, WclActorParse::ROLE_HEALER, $members);
            }

            $report->forceFill(['fights_imported_at' => now()])->save();
        });

        return ['fights_inserted' => $fightsInserted, 'parses_inserted' => $parsesInserted];
    }

    /**
     * @param  array<string,mixed>  $tableActors  Already extracted actor list.
     * @param  EloquentCollection<int, Member>  $members
     */
    private function writeParses(WclFight $fight, array $tableActors, string $role, EloquentCollection $members): int
    {
        $inserted = 0;
        // Index member ids by lowercased "name" prefix for matching.
        $byName = $members->keyBy(fn ($m) => mb_strtolower(explode('-', $m->name, 2)[0]));

        foreach ($tableActors as $actor) {
            if (! is_array($actor) || empty($actor['name'])) {
                continue;
            }
            $name = (string) $actor['name'];
            $memberId = $byName->get(mb_strtolower($name))?->id;

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
                    'parse_percentile' => null,   // populated by a follow-up rankings query
                    'bracket_percentile' => null,
                    'item_level' => isset($actor['itemLevel']) ? (int) $actor['itemLevel'] : null,
                    'raw_json' => $actor,
                ]
            );
            if ($parse->wasRecentlyCreated) $inserted++;
        }

        return $inserted;
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
