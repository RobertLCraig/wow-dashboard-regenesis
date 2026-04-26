<?php

namespace App\Jobs;

use App\Services\Sync\SyncStatus;
use App\Services\Wcl\WclReportImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Background WCL pull. Mirror of SyncRaiderioSnapshotJob - state cache,
 * mutex hand-off, set_time_limit bump - so the sync dashboard can show
 * "running" / "done" / "failed" identically across all sources.
 */
class SyncWclReportsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly ?int $startedByUserId = null,
        public readonly ?string $lockOwner = null,
    ) {}

    public function handle(): void
    {
        SyncStatus::set(SyncStatus::SOURCE_WCL, [
            'status' => SyncStatus::RUNNING,
            'started_at' => now()->toIso8601String(),
            'started_by_user_id' => $this->startedByUserId,
            'finished_at' => null,
            'summary' => null,
            'error' => null,
        ]);

        @set_time_limit(120);

        try {
            $result = WclReportImporter::fromConfig()->pull();

            SyncStatus::set(SyncStatus::SOURCE_WCL, [
                'status' => SyncStatus::DONE,
                'started_at' => SyncStatus::get(SyncStatus::SOURCE_WCL)['started_at'] ?? now()->toIso8601String(),
                'started_by_user_id' => $this->startedByUserId,
                'finished_at' => now()->toIso8601String(),
                'summary' => $result,
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('SyncWclReportsJob failed', ['message' => $e->getMessage()]);
            SyncStatus::set(SyncStatus::SOURCE_WCL, [
                'status' => SyncStatus::FAILED,
                'started_at' => SyncStatus::get(SyncStatus::SOURCE_WCL)['started_at'] ?? now()->toIso8601String(),
                'started_by_user_id' => $this->startedByUserId,
                'finished_at' => now()->toIso8601String(),
                'summary' => null,
                'error' => $e->getMessage(),
            ]);
        } finally {
            if ($this->lockOwner !== null) {
                Cache::restoreLock(SyncStatus::wclMutexKey(), $this->lockOwner)->release();
            }
        }
    }
}
