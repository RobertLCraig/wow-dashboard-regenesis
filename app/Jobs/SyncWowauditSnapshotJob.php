<?php

namespace App\Jobs;

use App\Services\Sync\SyncStatus;
use App\Services\Wowaudit\WowauditClient;
use App\Services\Wowaudit\WowauditSnapshotImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Mirror of SyncRaiderioSnapshotJob for the wowaudit pull. Only one
 * meaningful difference: the wowaudit importer is bounded by their team
 * roster (typically 20-25 mythic chars), so it's much cheaper than RIO
 * and rarely needs the queue worker fallback.
 */
class SyncWowauditSnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $guildKey,
        public readonly ?int $startedByUserId = null,
        public readonly ?string $lockOwner = null,
    ) {}

    public function handle(): void
    {
        SyncStatus::set(SyncStatus::SOURCE_WOWAUDIT, [
            'status' => SyncStatus::RUNNING,
            'started_at' => now()->toIso8601String(),
            'started_by_user_id' => $this->startedByUserId,
            'finished_at' => null,
            'summary' => null,
            'error' => null,
        ]);

        @set_time_limit(180);

        try {
            $client = WowauditClient::fromConfig();
            if (! $client->isConfigured()) {
                throw new \RuntimeException('WOWAUDIT_API_KEY is not set in .env');
            }

            $result = (new WowauditSnapshotImporter(
                client: $client,
                guildKey: $this->guildKey,
            ))->pullCurrentPeriod();

            SyncStatus::set(SyncStatus::SOURCE_WOWAUDIT, [
                'status' => SyncStatus::DONE,
                'started_at' => SyncStatus::get(SyncStatus::SOURCE_WOWAUDIT)['started_at'] ?? now()->toIso8601String(),
                'started_by_user_id' => $this->startedByUserId,
                'finished_at' => now()->toIso8601String(),
                'summary' => $result,
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('SyncWowauditSnapshotJob failed', ['message' => $e->getMessage()]);
            SyncStatus::set(SyncStatus::SOURCE_WOWAUDIT, [
                'status' => SyncStatus::FAILED,
                'started_at' => SyncStatus::get(SyncStatus::SOURCE_WOWAUDIT)['started_at'] ?? now()->toIso8601String(),
                'started_by_user_id' => $this->startedByUserId,
                'finished_at' => now()->toIso8601String(),
                'summary' => null,
                'error' => $e->getMessage(),
            ]);
        } finally {
            if ($this->lockOwner !== null) {
                Cache::restoreLock(SyncStatus::wowauditMutexKey(), $this->lockOwner)->release();
            }
        }
    }
}
