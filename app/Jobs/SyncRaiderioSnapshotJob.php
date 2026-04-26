<?php

namespace App\Jobs;

use App\Services\Raiderio\RaiderioClient;
use App\Services\Raiderio\RaiderioSnapshotImporter;
use App\Services\Sync\SyncStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Runs the Raider.IO importer in the background of the request that
 * dispatched it (via dispatch->afterResponse), or on a queue worker
 * when QUEUE_CONNECTION isn't 'sync'. Either way the controller has
 * already returned 200 to the officer; this is purely about doing the
 * actual work without holding the response open.
 *
 * Status is mirrored into Cache so the /admin/sync page can render
 * "running" / "done" / "failed" without polling the importer directly.
 * The mutex (held under SyncStatus::MUTEX_KEY) is acquired by the
 * dispatcher and released here via the matching owner token.
 */
class SyncRaiderioSnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $guildKey,
        public readonly ?int $startedByUserId = null,
        public readonly ?string $lockOwner = null,
    ) {}

    public function handle(): void
    {
        SyncStatus::set(SyncStatus::SOURCE_RAIDERIO, [
            'status' => SyncStatus::RUNNING,
            'started_at' => now()->toIso8601String(),
            'started_by_user_id' => $this->startedByUserId,
            'finished_at' => null,
            'summary' => null,
            'error' => null,
        ]);

        // Push past Hostinger's 30s default; the per-request HTTP timeout
        // inside the importer is the real bound.
        @set_time_limit(180);

        try {
            $result = (new RaiderioSnapshotImporter(
                client: RaiderioClient::fromConfig(),
                guildKey: $this->guildKey,
                requestDelayMs: (int) config('raiderio.request_delay_ms', 100),
                concurrency: (int) config('raiderio.sync_concurrency', 10),
            ))->pull();

            SyncStatus::set(SyncStatus::SOURCE_RAIDERIO, [
                'status' => SyncStatus::DONE,
                'started_at' => SyncStatus::get(SyncStatus::SOURCE_RAIDERIO)['started_at'] ?? now()->toIso8601String(),
                'started_by_user_id' => $this->startedByUserId,
                'finished_at' => now()->toIso8601String(),
                'summary' => $result,
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('SyncRaiderioSnapshotJob failed', ['message' => $e->getMessage()]);
            SyncStatus::set(SyncStatus::SOURCE_RAIDERIO, [
                'status' => SyncStatus::FAILED,
                'started_at' => SyncStatus::get(SyncStatus::SOURCE_RAIDERIO)['started_at'] ?? now()->toIso8601String(),
                'started_by_user_id' => $this->startedByUserId,
                'finished_at' => now()->toIso8601String(),
                'summary' => null,
                'error' => $e->getMessage(),
            ]);
        } finally {
            // Release the mutex regardless of outcome. We use the owner
            // token the dispatcher captured so a stale process can't
            // accidentally release someone else's lock.
            if ($this->lockOwner !== null) {
                Cache::restoreLock(SyncStatus::raiderioMutexKey(), $this->lockOwner)->release();
            }
        }
    }
}
