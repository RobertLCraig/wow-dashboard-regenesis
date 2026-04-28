<?php

namespace App\Jobs;

use App\Services\Blizzard\BlizzardClient;
use App\Services\Blizzard\BlizzardSnapshotImporter;
use App\Services\Blizzard\EquipmentSnapshotImporter;
use App\Services\Blizzard\GuildRosterImporter;
use App\Services\Blizzard\MplusSnapshotImporter;
use App\Services\Blizzard\RaidEncountersSnapshotImporter;
use App\Services\Sync\SyncStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Runs the Blizzard importer in the background of the request that
 * dispatched it (via dispatch->afterResponse), or on a queue worker
 * when QUEUE_CONNECTION isn't 'sync'. Mirrors SyncRaiderioSnapshotJob
 * shape so the dashboard's "running / done / failed" states render
 * the same way regardless of source.
 */
class SyncBlizzardSnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $guildKey,
        public readonly ?int $startedByUserId = null,
        public readonly ?string $lockOwner = null,
    ) {}

    public function handle(): void
    {
        SyncStatus::set(SyncStatus::SOURCE_BLIZZARD, [
            'status' => SyncStatus::RUNNING,
            'started_at' => now()->toIso8601String(),
            'started_by_user_id' => $this->startedByUserId,
            'finished_at' => null,
            'summary' => null,
            'error' => null,
        ]);

        // Push past Hostinger's 30s default; per-request HTTP timeout
        // inside the importer is the real bound.
        @set_time_limit(180);

        try {
            $client = BlizzardClient::fromConfig();

            // Roster first: it creates new member rows and stamps
            // blizzard_character_id + realm_slug, which the profile
            // importer keys off when picking who to fetch. Skipped
            // cleanly when guild slugs are unconfigured.
            $rosterRealmSlug = (string) config('blizzard.guild_realm_slug', '');
            $rosterNameSlug = (string) config('blizzard.guild_name_slug', '');
            $rosterResult = null;
            if ($rosterRealmSlug !== '' && $rosterNameSlug !== '') {
                $rosterResult = (new GuildRosterImporter(
                    client: $client,
                    guildKey: $this->guildKey,
                    guildRealmSlug: $rosterRealmSlug,
                    guildNameSlug: $rosterNameSlug,
                ))->pull();
            }

            $profileResult = (new BlizzardSnapshotImporter(
                client: $client,
                guildKey: $this->guildKey,
                requestDelayMs: (int) config('blizzard.request_delay_ms', 50),
                concurrency: (int) config('blizzard.sync_concurrency', 10),
            ))->pull();

            $equipmentResult = (new EquipmentSnapshotImporter(
                client: $client,
                guildKey: $this->guildKey,
                requestDelayMs: (int) config('blizzard.request_delay_ms', 50),
                concurrency: (int) config('blizzard.sync_concurrency', 10),
            ))->pull();

            $mplusResult = (new MplusSnapshotImporter(
                client: $client,
                guildKey: $this->guildKey,
                requestDelayMs: (int) config('blizzard.request_delay_ms', 50),
                concurrency: (int) config('blizzard.sync_concurrency', 10),
            ))->pull();

            $raidsResult = (new RaidEncountersSnapshotImporter(
                client: $client,
                guildKey: $this->guildKey,
                requestDelayMs: (int) config('blizzard.request_delay_ms', 50),
                concurrency: (int) config('blizzard.sync_concurrency', 10),
            ))->pull();

            // Flat keys so the sync dashboard's generic key/value
            // renderer surfaces them. The view skips array values.
            $result = [
                'roster_total' => $rosterResult['total_in_roster'] ?? null,
                'roster_inserted' => $rosterResult['inserted'] ?? null,
                'roster_updated' => $rosterResult['updated'] ?? null,
                'roster_not_seen' => $rosterResult['not_seen_this_pull'] ?? null,
                'profile_snapshot_id' => $profileResult['snapshot_id'] ?? null,
                'profile_members_queried' => $profileResult['members_queried'] ?? null,
                'profile_matched' => $profileResult['matched'] ?? null,
                'profile_missing' => $profileResult['missing'] ?? null,
                'profile_errored' => $profileResult['errored'] ?? null,
                'equipment_snapshot_id' => $equipmentResult['snapshot_id'] ?? null,
                'equipment_matched' => $equipmentResult['matched'] ?? null,
                'equipment_missing' => $equipmentResult['missing'] ?? null,
                'equipment_errored' => $equipmentResult['errored'] ?? null,
                'mplus_snapshot_id' => $mplusResult['snapshot_id'] ?? null,
                'mplus_matched' => $mplusResult['matched'] ?? null,
                'mplus_missing' => $mplusResult['missing'] ?? null,
                'mplus_errored' => $mplusResult['errored'] ?? null,
                'raids_snapshot_id' => $raidsResult['snapshot_id'] ?? null,
                'raids_matched' => $raidsResult['matched'] ?? null,
                'raids_missing' => $raidsResult['missing'] ?? null,
                'raids_errored' => $raidsResult['errored'] ?? null,
            ];

            SyncStatus::set(SyncStatus::SOURCE_BLIZZARD, [
                'status' => SyncStatus::DONE,
                'started_at' => SyncStatus::get(SyncStatus::SOURCE_BLIZZARD)['started_at'] ?? now()->toIso8601String(),
                'started_by_user_id' => $this->startedByUserId,
                'finished_at' => now()->toIso8601String(),
                'summary' => $result,
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('SyncBlizzardSnapshotJob failed', ['message' => $e->getMessage()]);
            SyncStatus::set(SyncStatus::SOURCE_BLIZZARD, [
                'status' => SyncStatus::FAILED,
                'started_at' => SyncStatus::get(SyncStatus::SOURCE_BLIZZARD)['started_at'] ?? now()->toIso8601String(),
                'started_by_user_id' => $this->startedByUserId,
                'finished_at' => now()->toIso8601String(),
                'summary' => null,
                'error' => $e->getMessage(),
            ]);
        } finally {
            if ($this->lockOwner !== null) {
                Cache::restoreLock(SyncStatus::blizzardMutexKey(), $this->lockOwner)->release();
            }
        }
    }
}
