<?php

namespace App\Jobs;

use App\Services\Blizzard\BlizzardClient;
use App\Services\Blizzard\BlizzardSnapshotImporter;
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
            $result = (new BlizzardSnapshotImporter(
                client: BlizzardClient::fromConfig(),
                guildKey: $this->guildKey,
                requestDelayMs: (int) config('blizzard.request_delay_ms', 50),
                concurrency: (int) config('blizzard.sync_concurrency', 10),
            ))->pull();

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
