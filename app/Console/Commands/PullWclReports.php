<?php

namespace App\Console\Commands;

use App\Services\Wcl\WclFightImporter;
use App\Services\Wcl\WclReportImporter;
use Illuminate\Console\Command;

/**
 * Pulls the latest WCL reports for the configured guild and upserts
 * them into wcl_reports.
 *
 *   php artisan wcl:pull
 *
 * Designed to run once a day (cheap: one GraphQL call). Short-circuits
 * when WCL_CLIENT_ID / WCL_CLIENT_SECRET aren't set so a pre-config
 * deploy doesn't error.
 */
class PullWclReports extends Command
{
    protected $signature = 'wcl:pull
        {--no-fights : Skip the per-report fights/parses backfill}
        {--max-fights=5 : Cap on how many reports to deep-import in one run}';

    protected $description = 'Pull the latest Warcraft Logs reports + (by default) backfill fights and parses';

    public function handle(): int
    {
        if (! config('wcl.client_id') || ! config('wcl.client_secret')) {
            $this->warn('WCL_CLIENT_ID / WCL_CLIENT_SECRET not set; skipping.');
            return self::SUCCESS;
        }

        try {
            $r = WclReportImporter::fromConfig()->pull();
        } catch (\Throwable $e) {
            $this->error('WCL reports pull failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Reports: fetched %d, %d new, %d updated. Latest: %s.',
            $r['fetched'], $r['inserted'], $r['updated'], $r['last_code'] ?? '-',
        ));

        if ($this->option('no-fights')) {
            return self::SUCCESS;
        }

        try {
            $f = WclFightImporter::fromConfig()->backfillUnimported(
                maxReports: (int) $this->option('max-fights'),
            );
        } catch (\Throwable $e) {
            $this->error('WCL fights backfill failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Fights: processed %d reports (%d new fights, %d new parses, %d errored).',
            $f['reports_processed'], $f['fights_inserted'], $f['parses_inserted'], $f['errored'],
        ));
        return self::SUCCESS;
    }
}
