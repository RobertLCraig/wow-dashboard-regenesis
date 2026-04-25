<?php

namespace App\Console\Commands;

use App\Services\Wowaudit\WowauditClient;
use App\Services\Wowaudit\WowauditSnapshotImporter;
use Illuminate\Console\Command;

/**
 * Pulls a fresh wowaudit snapshot for the current Blizzard period.
 *
 *   php artisan wowaudit:pull
 *
 * Designed to run hourly via the scheduler; safe to re-run (the
 * snapshots table dedupes by payload_hash so an unchanged payload is a
 * no-op, just a touched updated_at).
 */
class PullWowauditSnapshot extends Command
{
    protected $signature = 'wowaudit:pull';

    protected $description = 'Pull this period\'s wowaudit data into local snapshots';

    public function handle(): int
    {
        $client = WowauditClient::fromConfig();
        if (! $client->isConfigured()) {
            $this->warn('WOWAUDIT_API_KEY not set; skipping.');
            return self::SUCCESS;
        }

        $importer = new WowauditSnapshotImporter(
            client: $client,
            guildKey: (string) config('grm.guild_key'),
        );

        try {
            $result = $importer->pullCurrentPeriod();
        } catch (\Throwable $e) {
            $this->error('wowaudit pull failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Period %d: %d characters returned, %d matched to members, %d skipped (no GRM match). Snapshot #%d.',
            $result['period'],
            $result['characters_returned'],
            $result['matched'],
            $result['skipped'],
            $result['snapshot_id'],
        ));

        return self::SUCCESS;
    }
}
