<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sweep aged-out rows from the per-member snapshot tables.
 *
 *   php artisan snapshots:prune [--days=30] [--table=member_snapshots ...] [--dry-run]
 *
 * These tables (member_snapshots, member_equipment_snapshots, ...) are
 * append-only: every source pull writes a fresh, fat JSON row per member.
 * Unbounded, they grew past Hostinger's 3 GB per-database cap, which
 * auto-revoked write privileges and took the whole site down with a 500
 * on every request (session writes denied). See docs/planning/next-session.md.
 *
 * Retention policy: delete rows whose parent snapshot is older than the
 * window, EXCEPT always keep each member's most-recent row per source.
 * That protection means the current-state UI (roster, character, BiS, the
 * GRM diff's "previous row") never loses data regardless of the window -
 * churn/anniversary history lives in member_events, which this never touches.
 *
 * Idempotent and chunked (stays under the 120s statement-time budget).
 * A window of 0 is the explicit "never prune" signal.
 */
class PruneSnapshots extends Command
{
    protected $signature = 'snapshots:prune
        {--days= : Override the retention window in days (overrides config)}
        {--table=* : Limit to specific snapshot tables (default: all configured)}
        {--dry-run : Report what would be deleted without persisting}';

    protected $description = 'Drop aged-out per-member snapshot rows, keeping each member\'s latest per source';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('snapshots.retention_days', 30));
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, (int) config('snapshots.prune_chunk', 2000));

        if ($days <= 0) {
            $this->info('Retention disabled (days=0); nothing to prune.');
            return self::SUCCESS;
        }

        $tables = $this->option('table') ?: config('snapshots.tables', []);
        $cutoff = CarbonImmutable::now()->subDays($days);

        $this->info(sprintf(
            '%sPruning snapshot rows older than %s (%d days), keeping each member\'s latest per source.',
            $dryRun ? '[dry-run] ' : '',
            $cutoff->toDateString(),
            $days,
        ));

        $grandTotal = 0;

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                $this->warn(sprintf('  %-28s skipped (no such table)', $table));
                continue;
            }

            $protectedIds = $this->protectedRowIds($table);
            $deletable = $this->deletableQuery($table, $cutoff, $protectedIds);

            if ($dryRun) {
                $count = $deletable->count();
                $grandTotal += $count;
                $this->line(sprintf('  %-28s would prune %d (protecting %d latest)', $table, $count, count($protectedIds)));
                continue;
            }

            $deleted = 0;
            do {
                $ids = $this->deletableQuery($table, $cutoff, $protectedIds)
                    ->limit($chunk)
                    ->pluck($table.'.id')
                    ->all();

                if ($ids === []) {
                    break;
                }

                $deleted += DB::table($table)->whereIn('id', $ids)->delete();
            } while (count($ids) === $chunk);

            $grandTotal += $deleted;
            $this->line(sprintf('  %-28s pruned %d (protected %d latest)', $table, $deleted, count($protectedIds)));
        }

        $this->info(sprintf('%s%s %d snapshot rows across %d table(s).',
            $dryRun ? '[dry-run] ' : '',
            $dryRun ? 'Would prune' : 'Pruned',
            $grandTotal,
            count($tables),
        ));

        return self::SUCCESS;
    }

    /**
     * The child-row id of each member's most-recent snapshot per source.
     * These are protected from pruning at any age so the current-state UI
     * always has a row to read. Ties on captured_at protect both rows,
     * which is harmless.
     *
     * @return list<int>
     */
    private function protectedRowIds(string $table): array
    {
        $maxPerGroup = DB::table($table.' as c')
            ->join('snapshots as s', 's.id', '=', 'c.snapshot_id')
            ->groupBy('c.member_id', 's.source')
            ->select('c.member_id', 's.source', DB::raw('MAX(s.captured_at) as mx'));

        return DB::table($table.' as c')
            ->join('snapshots as s', 's.id', '=', 'c.snapshot_id')
            ->joinSub($maxPerGroup, 'g', function ($join) {
                $join->on('g.member_id', '=', 'c.member_id')
                    ->on('g.source', '=', 's.source')
                    ->on('g.mx', '=', 's.captured_at');
            })
            ->pluck('c.id')
            ->all();
    }

    /**
     * Rows whose parent snapshot predates the cutoff and which are not the
     * protected latest-per-member-per-source. Rebuilt fresh each chunk so
     * the LIMIT walks forward as rows are deleted.
     *
     * @param  list<int>  $protectedIds
     */
    private function deletableQuery(string $table, CarbonImmutable $cutoff, array $protectedIds): \Illuminate\Database\Query\Builder
    {
        return DB::table($table)
            ->join('snapshots as s', 's.id', '=', $table.'.snapshot_id')
            ->where('s.captured_at', '<', $cutoff)
            ->when($protectedIds !== [], fn ($q) => $q->whereNotIn($table.'.id', $protectedIds))
            ->select($table.'.id');
    }
}
