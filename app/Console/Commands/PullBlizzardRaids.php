<?php

namespace App\Console\Commands;

use App\Services\Blizzard\BlizzardClient;
use App\Services\Blizzard\RaidEncountersSnapshotImporter;
use Illuminate\Console\Command;

/**
 * Pulls /encounters/raids for every active member.
 *
 *   php artisan blizzard:pull-raids
 *
 * Captures the full per-character raid progression tree (every
 * expansion's instances, difficulties, encounters, last-kill
 * timestamps). No opt-in needed - covers anyone in the guild roster.
 * Short-circuits cleanly when BLIZZARD_CLIENT_ID/SECRET are unset.
 */
class PullBlizzardRaids extends Command
{
    protected $signature = 'blizzard:pull-raids {--min-level= : Override the level floor}';

    protected $description = 'Pull per-character raid progression (boss kills + difficulties) for every active member';

    public function handle(): int
    {
        $client = BlizzardClient::fromConfig();
        if (! $client->isConfigured()) {
            $this->info('blizzard:pull-raids skipped (BLIZZARD_CLIENT_ID / BLIZZARD_CLIENT_SECRET not set).');
            return self::SUCCESS;
        }

        $importer = new RaidEncountersSnapshotImporter(
            client: $client,
            guildKey: (string) config('grm.guild_key'),
            requestDelayMs: (int) config('blizzard.request_delay_ms', 50),
            minLevel: (int) ($this->option('min-level') ?? 70),
            concurrency: (int) config('blizzard.sync_concurrency', 10),
        );

        try {
            $result = $importer->pull();
        } catch (\Throwable $e) {
            $this->error('blizzard:pull-raids failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info(sprintf(
            '%d members queried: %d matched, %d missing on Blizzard, %d errored. Raids snapshot #%d.',
            $result['members_queried'],
            $result['matched'],
            $result['missing'],
            $result['errored'],
            $result['snapshot_id'],
        ));

        return self::SUCCESS;
    }
}
