<?php

namespace App\Services\Grm;

use App\Jobs\IngestSnapshotJob;
use App\Models\Snapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Shared sink for any caller that has a parsed GRM payload (already
 * decoded out of Lua / JSON) and wants it persisted as a snapshot:
 *
 *   - HTTP /api/ingest/grm   (PowerShell sync tool, gzipped JSON envelope)
 *   - /admin/sync GRM upload (officer drag-and-drop a SavedVariables.lua)
 *
 * Both paths converge here so dedupe + raw-storage + job dispatch is
 * defined exactly once.
 */
class GrmSnapshotIngester
{
    /**
     * @param  array<string,mixed>  $payload  The full parsed GRM table
     *         (i.e. ['GRM_GuildMemberHistory_Save' => [...], ...]).
     * @return array{snapshot_id:int, was_duplicate:bool, captured_at:string}
     */
    public function ingest(
        string $guildKey,
        array $payload,
        ?CarbonImmutable $capturedAt = null,
        ?string $grmVersion = null,
    ): array {
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $capturedAt ??= CarbonImmutable::now();

        $duplicate = Snapshot::query()
            ->where('guild_key', $guildKey)
            ->where('source', Snapshot::SOURCE_GRM)
            ->where('payload_hash', $hash)
            ->first();
        if ($duplicate) {
            return [
                'snapshot_id' => $duplicate->id,
                'was_duplicate' => true,
                'captured_at' => $duplicate->captured_at?->toIso8601String() ?? '',
            ];
        }

        // Raw envelope persisted gzipped under a ULID for sortable
        // uniqueness. Mirrors the API ingest layout so the differ /
        // replay tools can find both side by side.
        $rawPath = sprintf(
            'snapshots/%s/%s.json.gz',
            $guildKey,
            (string) Str::ulid(),
        );
        $envelope = [
            'guild_key' => $guildKey,
            'payload' => $payload,
            'captured_at' => $capturedAt->toIso8601String(),
            'grm_version' => $grmVersion,
        ];
        Storage::put($rawPath, gzencode(json_encode($envelope, JSON_THROW_ON_ERROR), 9));

        $snapshot = Snapshot::query()->create([
            'guild_key' => $guildKey,
            'source' => Snapshot::SOURCE_GRM,
            'captured_at' => $capturedAt,
            'payload_hash' => $hash,
            'raw_path' => $rawPath,
            'grm_version' => $grmVersion,
        ]);

        IngestSnapshotJob::dispatch($snapshot->id);

        return [
            'snapshot_id' => $snapshot->id,
            'was_duplicate' => false,
            'captured_at' => $snapshot->captured_at?->toIso8601String() ?? '',
        ];
    }
}
