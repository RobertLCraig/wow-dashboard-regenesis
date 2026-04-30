<?php

namespace App\Console\Commands;

use App\Services\Blizzard\BlizzardClient;
use App\Services\Blizzard\BlizzardSnapshotImporter;
use Illuminate\Console\Command;

/**
 * Pulls a fresh Blizzard character profile for every active member in
 * the local roster.
 *
 *   php artisan blizzard:pull
 *
 * Designed to run on schedule (see routes/console.php). Short-circuits
 * cleanly when BLIZZARD_CLIENT_ID / BLIZZARD_CLIENT_SECRET are unset
 * so a deploy without credentials configured doesn't error.
 */
class PullBlizzardSnapshot extends Command
{
    protected $signature = 'blizzard:pull
        {--min-level= : Override the level floor}
        {--limit= : Cap how many members to fetch this run; oldest-first}';

    protected $description = 'Pull current Blizzard profile data for every active member into a fresh snapshot';

    public function handle(): int
    {
        $client = BlizzardClient::fromConfig();
        if (! $client->isConfigured()) {
            $this->info('blizzard:pull skipped (BLIZZARD_CLIENT_ID / BLIZZARD_CLIENT_SECRET not set).');
            return self::SUCCESS;
        }

        $limitOpt = $this->option('limit');
        $limit = is_numeric($limitOpt) && (int) $limitOpt > 0 ? (int) $limitOpt : null;

        $importer = new BlizzardSnapshotImporter(
            client: $client,
            guildKey: (string) config('grm.guild_key'),
            requestDelayMs: (int) config('blizzard.request_delay_ms', 50),
            minLevel: (int) ($this->option('min-level') ?? 70),
            concurrency: (int) config('blizzard.sync_concurrency', 10),
            limit: $limit,
        );

        try {
            $result = $importer->pull();
        } catch (\Throwable $e) {
            $this->error('blizzard pull failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info(sprintf(
            '%d members queried: %d matched, %d missing on Blizzard, %d errored. Snapshot #%d.',
            $result['members_queried'],
            $result['matched'],
            $result['missing'],
            $result['errored'],
            $result['snapshot_id'],
        ));

        return self::SUCCESS;
    }
}
