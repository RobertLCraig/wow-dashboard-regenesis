<?php

namespace App\Http\Controllers\Ingest;

use App\Http\Controllers\Controller;
use App\Jobs\IngestSnapshotJob;
use App\Models\Snapshot;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Receives gzipped JSON envelopes from tools/grm-sync/grm-sync.ps1.
 *
 * The middleware (IngestBearerToken) has already authenticated the
 * request. Body decoding is the controller's job because Laravel only
 * auto-decompresses application/json bodies for some web servers.
 */
class GrmSnapshotController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $body = $request->getContent();
        if ($body === '' || $body === false) {
            return response()->json(['error' => 'empty body'], 400);
        }

        if (strtolower((string) $request->header('Content-Encoding')) === 'gzip') {
            $decoded = @gzdecode($body);
            if ($decoded === false) {
                return response()->json(['error' => 'gzip decode failed'], 400);
            }
            $body = $decoded;
        }

        try {
            $envelope = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return response()->json(['error' => 'invalid json: ' . $e->getMessage()], 400);
        }

        $guildKey = $envelope['guild_key'] ?? null;
        $payload = $envelope['payload'] ?? null;
        if (! is_string($guildKey) || ! is_array($payload)) {
            return response()->json(['error' => 'envelope missing guild_key or payload'], 422);
        }
        if ($guildKey !== config('grm.guild_key')) {
            return response()->json(['error' => "unexpected guild_key: $guildKey"], 422);
        }

        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        $duplicate = Snapshot::query()
            ->where('guild_key', $guildKey)
            ->where('source', 'grm')
            ->where('payload_hash', $hash)
            ->first();
        if ($duplicate) {
            return response()->json([
                'noop' => true,
                'snapshot_id' => $duplicate->id,
                'reason' => 'payload_hash matches existing snapshot',
            ], 200);
        }

        // Persist the raw envelope (gzipped) to storage for later replay
        // / debugging. Keyed by ULID for sortability + uniqueness.
        $rawPath = sprintf(
            'snapshots/%s/%s.json.gz',
            $guildKey,
            (string) Str::ulid(),
        );
        Storage::put($rawPath, gzencode(json_encode($envelope, JSON_THROW_ON_ERROR), 9));

        $capturedAt = isset($envelope['captured_at']) && is_string($envelope['captured_at'])
            ? CarbonImmutable::parse($envelope['captured_at'])
            : CarbonImmutable::now();

        $snapshot = Snapshot::query()->create([
            'guild_key' => $guildKey,
            'source' => 'grm',
            'captured_at' => $capturedAt,
            'payload_hash' => $hash,
            'raw_path' => $rawPath,
            'grm_version' => $envelope['grm_version'] ?? null,
        ]);

        IngestSnapshotJob::dispatch($snapshot->id);

        return response()->json([
            'snapshot_id' => $snapshot->id,
            'captured_at' => $snapshot->captured_at?->toIso8601String(),
            'queued' => true,
        ], 202);
    }
}
