<?php

namespace App\Jobs;

use App\Models\Snapshot;
use App\Services\Grm\GrmNormalizer;
use App\Services\Grm\GrmSnapshotDiffer;
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
 * Kept on the queue so the controller can return a 202 in <500ms even
 * when the payload is multi-MB. On Hostinger the queue worker is fired
 * by `php artisan schedule:run` every minute via cron.
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

        $payload = $this->readPayload($snapshot);
        if (! $payload) {
            Log::error('IngestSnapshotJob: payload unreadable', ['snapshot_id' => $snapshot->id, 'raw_path' => $snapshot->raw_path]);
            return;
        }

        $normalizer = new GrmNormalizer($snapshot->guild_key);
        $counts = $normalizer->apply($snapshot, $payload);

        $differ = new GrmSnapshotDiffer($snapshot->guild_key, (int) config('grm.inactive_days', 30));
        $events = $differ->diff($snapshot);

        Log::info('IngestSnapshotJob: ingested', [
            'snapshot_id' => $snapshot->id,
            'guild_key' => $snapshot->guild_key,
            'normalized' => $counts,
            'events' => $events,
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
