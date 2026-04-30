<?php

namespace App\Console\Commands;

use App\Services\Blizzard\BlizzardClient;
use App\Services\Blizzard\EquipmentSnapshotImporter;
use Illuminate\Console\Command;

/**
 * Pulls per-piece gear from /equipment for every active member.
 *
 *   php artisan blizzard:pull-equipment
 *
 * Designed to run on schedule (see routes/console.php). Stores one
 * MemberEquipmentSnapshot row per character, keyed to a Snapshot row
 * stamped source='blizzard_equipment'. Short-circuits cleanly when
 * BLIZZARD_CLIENT_ID / BLIZZARD_CLIENT_SECRET are unset.
 */
class PullBlizzardEquipment extends Command
{
    protected $signature = 'blizzard:pull-equipment
        {--min-level= : Override the level floor}
        {--limit= : Cap how many members to fetch this run; oldest-first}';

    protected $description = 'Pull per-piece gear (with enchants/sockets) for every active member into a fresh equipment snapshot';

    public function handle(): int
    {
        $client = BlizzardClient::fromConfig();
        if (! $client->isConfigured()) {
            $this->info('blizzard:pull-equipment skipped (BLIZZARD_CLIENT_ID / BLIZZARD_CLIENT_SECRET not set).');
            return self::SUCCESS;
        }

        $limitOpt = $this->option('limit');
        $limit = is_numeric($limitOpt) && (int) $limitOpt > 0 ? (int) $limitOpt : null;

        $importer = new EquipmentSnapshotImporter(
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
            $this->error('blizzard:pull-equipment failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info(sprintf(
            '%d members queried: %d matched, %d missing on Blizzard, %d errored. Equipment snapshot #%d.',
            $result['members_queried'],
            $result['matched'],
            $result['missing'],
            $result['errored'],
            $result['snapshot_id'],
        ));

        return self::SUCCESS;
    }
}
