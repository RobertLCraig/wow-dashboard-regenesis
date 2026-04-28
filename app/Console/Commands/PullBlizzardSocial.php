<?php

namespace App\Console\Commands;

use App\Services\Blizzard\BlizzardClient;
use App\Services\Blizzard\SocialSnapshotImporter;
use Illuminate\Console\Command;

/**
 * Pulls character media + achievements + collections (mounts, pets,
 * toys, transmogs) for every active member.
 *
 *   php artisan blizzard:pull-social
 *
 * Six endpoints per character. Heaviest of the Blizzard pulls; runs
 * less frequently than the others on schedule. Short-circuits cleanly
 * when BLIZZARD_CLIENT_ID/SECRET are unset.
 */
class PullBlizzardSocial extends Command
{
    protected $signature = 'blizzard:pull-social {--min-level= : Override the level floor}';

    protected $description = 'Pull character media + achievements + collections for every active member';

    public function handle(): int
    {
        $client = BlizzardClient::fromConfig();
        if (! $client->isConfigured()) {
            $this->info('blizzard:pull-social skipped (BLIZZARD_CLIENT_ID / BLIZZARD_CLIENT_SECRET not set).');
            return self::SUCCESS;
        }

        $importer = new SocialSnapshotImporter(
            client: $client,
            guildKey: (string) config('grm.guild_key'),
            requestDelayMs: (int) config('blizzard.request_delay_ms', 50),
            minLevel: (int) ($this->option('min-level') ?? 70),
            concurrency: (int) config('blizzard.sync_concurrency', 10),
        );

        try {
            $result = $importer->pull();
        } catch (\Throwable $e) {
            $this->error('blizzard:pull-social failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info(sprintf(
            '%d members queried: %d matched at least one endpoint, %d missing entirely, %d HTTP errors. Social snapshot #%d.',
            $result['members_queried'],
            $result['matched'],
            $result['missing'],
            $result['errored'],
            $result['snapshot_id'],
        ));

        return self::SUCCESS;
    }
}
