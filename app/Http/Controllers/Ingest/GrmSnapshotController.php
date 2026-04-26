<?php

namespace App\Http\Controllers\Ingest;

use App\Http\Controllers\Controller;
use App\Services\Grm\GrmSnapshotIngester;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $capturedAt = isset($envelope['captured_at']) && is_string($envelope['captured_at'])
            ? CarbonImmutable::parse($envelope['captured_at'])
            : null;
        $grmVersion = is_string($envelope['grm_version'] ?? null) ? $envelope['grm_version'] : null;

        $result = (new GrmSnapshotIngester())->ingest(
            guildKey: $guildKey,
            payload: $payload,
            capturedAt: $capturedAt,
            grmVersion: $grmVersion,
        );

        if ($result['was_duplicate']) {
            return response()->json([
                'noop' => true,
                'snapshot_id' => $result['snapshot_id'],
                'reason' => 'payload_hash matches existing snapshot',
            ], 200);
        }

        return response()->json([
            'snapshot_id' => $result['snapshot_id'],
            'captured_at' => $result['captured_at'],
            'queued' => true,
        ], 202);
    }
}
