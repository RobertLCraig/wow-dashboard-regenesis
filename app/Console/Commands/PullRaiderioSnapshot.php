<?php

namespace App\Console\Commands;

use App\Services\Raiderio\RaiderioClient;
use App\Services\Raiderio\RaiderioSnapshotImporter;
use Illuminate\Console\Command;

/**
 * Pulls a fresh Raider.IO snapshot for every active member in the local
 * roster.
 *
 *   php artisan raiderio:pull
 *
 * Designed to run twice-daily via the scheduler. No API key needed -
 * Raider.IO's character profile endpoint is public read-only.
 */
class PullRaiderioSnapshot extends Command
{
    protected $signature = 'raiderio:pull
        {--min-level= : Override the level floor}
        {--limit= : Cap how many members to fetch this run; oldest-first}';

    protected $description = 'Pull current Raider.IO data for every active member into a fresh snapshot';

    public function handle(): int
    {
        $client = RaiderioClient::fromConfig();
        $limitOpt = $this->option('limit');
        $limit = is_numeric($limitOpt) && (int) $limitOpt > 0 ? (int) $limitOpt : null;
        $importer = new RaiderioSnapshotImporter(
            client: $client,
            guildKey: (string) config('grm.guild_key'),
            requestDelayMs: (int) config('raiderio.request_delay_ms', 1500),
            minLevel: (int) ($this->option('min-level') ?? 70),
            concurrency: (int) config('raiderio.sync_concurrency', 1),
            limit: $limit,
        );

        try {
            $result = $importer->pull();
        } catch (\Throwable $e) {
            $this->error('raiderio pull failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info(sprintf(
            '%d members queried: %d matched, %d missing on RIO, %d errored. Snapshot #%d.',
            $result['members_queried'],
            $result['matched'],
            $result['missing'],
            $result['errored'],
            $result['snapshot_id'],
        ));

        return self::SUCCESS;
    }
}
