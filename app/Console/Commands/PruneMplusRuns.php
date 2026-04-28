<?php

namespace App\Console\Commands;

use App\Models\MemberMplusRun;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Sweep old member_mplus_runs rows past the retention window.
 *
 *   php artisan mplus:prune-runs [--days=180] [--dry-run]
 *
 * Default window comes from config('raiderio.runs_retention_days')
 * which is env-overridable via MPLUS_RUN_RETENTION_DAYS. Window of 0
 * is the explicit "never prune" signal - the command short-circuits
 * with a message and exits 0 so the scheduler doesn't error.
 *
 * Idempotent. Safe to run repeatedly (subsequent runs are no-ops once
 * the cutoff catches up). Logs the count for the deploy log.
 */
class PruneMplusRuns extends Command
{
    protected $signature = 'mplus:prune-runs
        {--days= : Override the retention window (overrides config)}
        {--dry-run : Report what would be deleted without persisting}';

    protected $description = 'Drop M+ run rows older than the retention window';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('raiderio.runs_retention_days', 180));
        $dryRun = (bool) $this->option('dry-run');

        if ($days <= 0) {
            $this->info('Retention disabled (days=0); nothing to prune.');
            return self::SUCCESS;
        }

        $cutoff = CarbonImmutable::now()->subDays($days);

        $query = MemberMplusRun::query()->where('completed_at', '<', $cutoff);
        $count = $query->count();

        if ($count === 0) {
            $this->info(sprintf('No runs older than %d days (cutoff %s).', $days, $cutoff->toDateString()));
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info(sprintf('[dry-run] Would prune %d runs older than %s (%d days).',
                $count, $cutoff->toDateString(), $days));
            return self::SUCCESS;
        }

        $deleted = $query->delete();
        $this->info(sprintf('Pruned %d runs older than %s (%d days).',
            $deleted, $cutoff->toDateString(), $days));

        return self::SUCCESS;
    }
}
