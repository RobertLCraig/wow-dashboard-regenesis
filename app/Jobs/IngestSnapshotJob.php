<?php

namespace App\Jobs;

use App\Models\Snapshot;
use App\Services\Grm\GrmNormalizer;
use App\Services\Grm\GrmSnapshotDiffer;
use App\Services\Sync\SyncStatus;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Heavy-lifting job: read the snapshot's gzipped raw payload from disk,
 * decode it, run the normalizer, then run the differ.
 *
 * Two dispatch paths:
 *   - Upload (officer drag-and-drop): runs sync via dispatchSync so the
 *     officer sees the full normalize/diff counts on the next redirect.
 *   - API (PowerShell push): queued, returns 202 in <500ms.
 *
 * For grm-source snapshots the job also pushes staged updates into
 * SyncStatus ('normalizing', 'diffing', 'done', 'failed') so the
 * dashboard's per-source panel shows live progress whichever path
 * ingested the snapshot.
 */
class IngestSnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $snapshotId,
    ) {}

    public function handle(): void
    {
        $snapshot = Snapshot::query()->find($this->snapshotId);
        if (! $snapshot) {
            Log::warning('IngestSnapshotJob: snapshot row gone', ['id' => $this->snapshotId]);
            return;
        }

        $isGrm = $snapshot->source === Snapshot::SOURCE_GRM;

        try {
            $this->updateStatus($isGrm, SyncStatus::RUNNING, 'normalizing', []);

            $payload = $this->readPayload($snapshot);
            if (! $payload) {
                Log::error('IngestSnapshotJob: payload unreadable', ['snapshot_id' => $snapshot->id, 'raw_path' => $snapshot->raw_path]);
                $this->updateStatus($isGrm, SyncStatus::FAILED, 'normalizing', [], 'Snapshot payload unreadable from raw_path: ' . $snapshot->raw_path);
                return;
            }

            $normalizer = new GrmNormalizer($snapshot->guild_key);
            $counts = $normalizer->apply($snapshot, $payload);

            $this->updateStatus($isGrm, SyncStatus::RUNNING, 'diffing', [
                'members_ingested' => $counts['members'] ?? 0,
                'log_events_added' => $counts['log_events'] ?? 0,
                'alt_groups_ingested' => $counts['alt_groups'] ?? 0,
            ]);

            $differ = new GrmSnapshotDiffer($snapshot->guild_key, (int) config('grm.inactive_days', 30));
            $events = $differ->diff($snapshot);

            $this->updateStatus($isGrm, SyncStatus::DONE, 'done', [
                'change_events_emitted' => array_sum($events),
                'change_events_breakdown' => $events,
            ]);

            Log::info('IngestSnapshotJob: ingested', [
                'snapshot_id' => $snapshot->id,
                'guild_key' => $snapshot->guild_key,
                'normalized' => $counts,
                'events' => $events,
            ]);
        } catch (\Throwable $e) {
            $this->updateStatus($isGrm, SyncStatus::FAILED, 'normalizing', [], $e->getMessage());
            throw $e;
        }
    }

    /**
     * Merge staged progress into the cached SyncStatus for grm uploads.
     * No-op for other sources (raid-helper / wowaudit / wcl manage their
     * own status writes).
     *
     * @param  array<string,mixed>  $extraSummary
     */
    private function updateStatus(bool $isGrm, string $status, ?string $stage, array $extraSummary, ?string $error = null): void
    {
        if (! $isGrm) {
            return;
        }

        $existing = SyncStatus::get(SyncStatus::SOURCE_GRM) ?? [];
        $existingSummary = is_array($existing['summary'] ?? null) ? $existing['summary'] : [];
        $finished = in_array($status, [SyncStatus::DONE, SyncStatus::FAILED], true);

        SyncStatus::set(SyncStatus::SOURCE_GRM, [
            'status' => $status,
            'stage' => $stage,
            'started_at' => $existing['started_at'] ?? CarbonImmutable::now()->toIso8601String(),
            'started_by_user_id' => $existing['started_by_user_id'] ?? null,
            'finished_at' => $finished ? CarbonImmutable::now()->toIso8601String() : ($existing['finished_at'] ?? null),
            'summary' => array_merge($existingSummary, $extraSummary),
            'error' => $error ?? ($finished ? null : ($existing['error'] ?? null)),
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readPayload(Snapshot $snapshot): ?array
    {
        $path = $snapshot->raw_path;
        if (! $path) {
            return null;
        }
        // Use the Storage facade rather than storage_path() so the path
        // resolves correctly under Storage::fake() in tests AND in
        // production where the local disk maps to storage/app.
        if (! Storage::exists($path)) {
            return null;
        }
        $gz = Storage::get($path);
        if ($gz === null || $gz === false) {
            return null;
        }
        $json = @gzdecode($gz);
        if ($json === false) {
            // Maybe was stored uncompressed (test fixtures). Try direct.
            $json = $gz;
        }
        try {
            $envelope = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($envelope['payload'] ?? null) ? $envelope['payload'] : null;
    }
}
