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
    protected $signature = 'raiderio:pull {--min-level= : Override the level floor}';

    protected $description = 'Pull current Raider.IO data for every active member into a fresh snapshot';

    public function handle(): int
    {
        $client = RaiderioClient::fromConfig();
        $importer = new RaiderioSnapshotImporter(
            client: $client,
            guildKey: (string) config('grm.guild_key'),
            requestDelayMs: (int) config('raiderio.request_delay_ms', 100),
            minLevel: (int) ($this->option('min-level') ?? 70),
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
