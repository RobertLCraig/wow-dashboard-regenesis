<?php

namespace App\Console\Commands;

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
    protected $signature = 'wcl:pull';

    protected $description = 'Pull the latest Warcraft Logs reports for the configured guild';

    public function handle(): int
    {
        if (! config('wcl.client_id') || ! config('wcl.client_secret')) {
            $this->warn('WCL_CLIENT_ID / WCL_CLIENT_SECRET not set; skipping.');
            return self::SUCCESS;
        }

        try {
            $r = WclReportImporter::fromConfig()->pull();
        } catch (\Throwable $e) {
            $this->error('WCL pull failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Fetched %d reports: %d new, %d updated. Latest code: %s.',
            $r['fetched'], $r['inserted'], $r['updated'], $r['last_code'] ?? '-',
        ));
        return self::SUCCESS;
    }
}
