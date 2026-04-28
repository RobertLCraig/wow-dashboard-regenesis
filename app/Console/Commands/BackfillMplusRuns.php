<?php

namespace App\Console\Commands;

use App\Models\MemberMplusRun;
use App\Models\MemberSnapshot;
use App\Models\Snapshot;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Walk every stored Raider.IO MemberSnapshot.raw_json and recover any
 * individual runs we can find into member_mplus_runs.
 *
 *   php artisan mplus:backfill-runs [--source=raiderio] [--dry-run]
 *
 * Historically we only requested `mythic_plus_weekly_highest_level_runs`,
 * so that's the only field with usable rows in the archive. Each
 * weekly-best entry is a real run with a `completed_at`, so this
 * recovery is one-sided (no fabricated data) and merges cleanly into
 * the runs table via the (member_id, completed_at) unique key.
 *
 * Idempotent. Re-running just bumps last_seen_at on rows that already
 * exist; it cannot duplicate. Safe to run repeatedly while iterating.
 */
class BackfillMplusRuns extends Command
{
    protected $signature = 'mplus:backfill-runs
        {--source=raiderio : Snapshot source to walk}
        {--dry-run : Report counts without persisting}';

    protected $description = 'Recover historical M+ runs from existing snapshot raw_json blobs';

    public function handle(): int
    {
        $source = (string) $this->option('source');
        $dryRun = (bool) $this->option('dry-run');

        // Walk snapshots in order so first_seen_at on each backfilled
        // run reflects the earliest archive that mentioned it. That
        // makes the data more honest if the user later inspects the
        // provenance columns: "this run first appeared in our system
        // at X" not "we discovered it during backfill".
        $snapshots = Snapshot::query()
            ->where('source', $source)
            ->orderBy('captured_at')
            ->orderBy('id')
            ->get(['id', 'captured_at']);

        $this->info(sprintf('Walking %d %s snapshots.', $snapshots->count(), $source));

        $considered = 0;
        $persisted = 0;
        $skipped = 0;

        foreach ($snapshots as $snapshot) {
            $capturedAt = $snapshot->captured_at instanceof CarbonImmutable
                ? $snapshot->captured_at
                : CarbonImmutable::parse((string) $snapshot->captured_at);

            $rows = MemberSnapshot::query()
                ->where('snapshot_id', $snapshot->id)
                ->whereNotNull('raw_json')
                ->get(['member_id', 'raw_json']);

            $batch = [];
            foreach ($rows as $row) {
                $body = is_array($row->raw_json) ? $row->raw_json : null;
                if (! $body) {
                    continue;
                }

                $seasonSlug = $this->seasonSlug($body);

                foreach ($this->runFields() as $field => $sourceTag) {
                    $list = $body[$field] ?? null;
                    if (! is_array($list)) {
                        continue;
                    }
                    foreach ($list as $raw) {
                        if (! is_array($raw)) {
                            continue;
                        }
                        $considered++;
                        $normalized = $this->normalizeRun($raw, $sourceTag, $row->member_id, $snapshot->id, $seasonSlug, $capturedAt);
                        if ($normalized === null) {
                            $skipped++;
                            continue;
                        }
                        // PHP-side dedupe within this snapshot pass so the
                        // upsert payload doesn't carry duplicate primary
                        // keys (the unique index on the table protects us
                        // anyway; this just keeps the batch clean).
                        $key = $normalized['member_id'] . '|' . $normalized['completed_at'];
                        if (isset($batch[$key]) && $batch[$key]['score'] !== null) {
                            continue;
                        }
                        $batch[$key] = $normalized;
                    }
                }
            }

            if ($batch === []) {
                continue;
            }

            $persisted += count($batch);
            if ($dryRun) {
                continue;
            }

            // Same split as the live importer: rows with score update the
            // score column on conflict, rows without score don't.
            $withScore = [];
            $withoutScore = [];
            foreach ($batch as $row) {
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

        $this->info(sprintf(
            '%s%d runs %s, %d skipped (missing completed_at), %d candidates considered.',
            $dryRun ? '[dry-run] ' : '',
            $persisted,
            $dryRun ? 'would persist' : 'persisted',
            $skipped,
            $considered,
        ));

        return self::SUCCESS;
    }

    /**
     * @return array<string,string> field name => source tag
     */
    private function runFields(): array
    {
        return [
            'mythic_plus_best_runs' => MemberMplusRun::SOURCE_SEASON_BEST,
            'mythic_plus_alternate_runs' => MemberMplusRun::SOURCE_ALTERNATE,
            'mythic_plus_weekly_highest_level_runs' => MemberMplusRun::SOURCE_WEEKLY_BEST,
            'mythic_plus_previous_weekly_highest_level_runs' => MemberMplusRun::SOURCE_PREV_WEEKLY_BEST,
            'mythic_plus_recent_runs' => MemberMplusRun::SOURCE_RECENT,
        ];
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>|null
     */
    private function normalizeRun(array $raw, string $source, int $memberId, int $snapshotId, ?string $seasonSlug, CarbonImmutable $observedAt): ?array
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
            'first_seen_at' => $observedAt->toDateTimeString(),
            'last_seen_at' => $observedAt->toDateTimeString(),
            'raw_json' => json_encode($raw),
            'created_at' => $observedAt->toDateTimeString(),
            'updated_at' => $observedAt->toDateTimeString(),
        ];
    }

    /**
     * @param  array<string,mixed>  $body
     */
    private function seasonSlug(array $body): ?string
    {
        $seasons = $body['mythic_plus_scores_by_season'] ?? null;
        if (! is_array($seasons) || $seasons === []) {
            return null;
        }
        $slug = $seasons[0]['season'] ?? null;
        return is_string($slug) && $slug !== '' ? mb_substr($slug, 0, 32) : null;
    }
}
