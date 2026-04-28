<?php

namespace App\Console\Commands;

use App\Services\Blizzard\BlizzardClient;
use App\Services\Blizzard\MplusSnapshotImporter;
use Illuminate\Console\Command;

/**
 * Pulls Blizzard's mythic-keystone-profile for every active member.
 *
 *   php artisan blizzard:pull-mplus
 *
 * Stored alongside (not replacing) the Raider.IO M+ feed so we have a
 * canonical source for cross-validation and RIO outage fallback.
 * Short-circuits cleanly when BLIZZARD_CLIENT_ID/SECRET are unset.
 */
class PullBlizzardMplus extends Command
{
    protected $signature = 'blizzard:pull-mplus {--min-level= : Override the level floor}';

    protected $description = 'Pull Blizzard mythic-keystone-profile for every active member';

    public function handle(): int
    {
        $client = BlizzardClient::fromConfig();
        if (! $client->isConfigured()) {
            $this->info('blizzard:pull-mplus skipped (BLIZZARD_CLIENT_ID / BLIZZARD_CLIENT_SECRET not set).');
            return self::SUCCESS;
        }

        $importer = new MplusSnapshotImporter(
            client: $client,
            guildKey: (string) config('grm.guild_key'),
            requestDelayMs: (int) config('blizzard.request_delay_ms', 50),
            minLevel: (int) ($this->option('min-level') ?? 70),
            concurrency: (int) config('blizzard.sync_concurrency', 10),
        );

        try {
            $result = $importer->pull();
        } catch (\Throwable $e) {
            $this->error('blizzard:pull-mplus failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info(sprintf(
            '%d members queried: %d matched, %d missing on Blizzard (no runs / unknown char), %d errored. M+ snapshot #%d.',
            $result['members_queried'],
            $result['matched'],
            $result['missing'],
            $result['errored'],
            $result['snapshot_id'],
        ));

        return self::SUCCESS;
    }
}
