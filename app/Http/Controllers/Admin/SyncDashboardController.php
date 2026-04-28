<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\IngestSnapshotJob;
use App\Models\Snapshot;
use App\Models\WclReport;
use App\Services\Grm\GrmSnapshotIngester;
use App\Services\Grm\LuaTableParser;
use App\Services\Sync\SyncStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * One page per source where officers see "when was the last sync, what
 * happened, click here to do another, drop a SavedVariables.lua here".
 *
 * Polling is plain HTML meta-refresh while at least one source is
 * mid-sync; no JS needed. Each source's "what's happening" comes from
 * SyncStatus (a Cache wrapper), persisted snapshot timestamps come from
 * the snapshots table.
 */
class SyncDashboardController extends Controller
{
    public function index(): View
    {
        $guildKey = (string) config('grm.guild_key');

        $grm = $this->latestSnapshot($guildKey, Snapshot::SOURCE_GRM);
        $woa = $this->latestSnapshot($guildKey, Snapshot::SOURCE_WOWAUDIT);
        $rio = $this->latestSnapshot($guildKey, Snapshot::SOURCE_RAIDERIO);
        $bnet = $this->latestSnapshot($guildKey, Snapshot::SOURCE_BLIZZARD);
        $wcl = WclReport::query()->latest('captured_at')->first();
        $wclTotal = WclReport::query()->count();

        $sources = [
            SyncStatus::SOURCE_GRM => [
                'label' => 'GRM (in-game)',
                'description' => 'In-game Guild_Roster_Manager addon. Pushed from your PC by the PowerShell sync tool every 30 minutes; you can also drop a SavedVariables.lua file here for an immediate import.',
                'last_seen_at' => $grm?->captured_at,
                'last_summary' => $grm ? "{$grm->member_count} members" : null,
                'state' => SyncStatus::get(SyncStatus::SOURCE_GRM),
                'cadence' => 'Push: every 30 min from your PC.',
                'has_button' => false,
                'has_upload' => true,
            ],
            SyncStatus::SOURCE_RAIDHELPER => [
                'label' => 'Raid-Helper',
                'description' => 'Discord raid signups. Real-time push via webhook + a daily safety-net pull.',
                'last_seen_at' => null,
                'last_summary' => null,
                'state' => SyncStatus::get(SyncStatus::SOURCE_RAIDHELPER),
                'cadence' => 'Push: webhook on event create/update/delete. Pull: daily 06:15 UK.',
                'has_button' => false,
                'has_upload' => false,
            ],
            SyncStatus::SOURCE_WOWAUDIT => [
                'label' => 'Wowaudit',
                'description' => 'Mythic team\'s vault + ilvl tracker. Pulled hourly from wowaudit.com.',
                'last_seen_at' => $woa?->captured_at,
                'last_summary' => $woa ? "{$woa->member_count} members" : null,
                'state' => SyncStatus::get(SyncStatus::SOURCE_WOWAUDIT),
                'cadence' => 'Pull: hourly.',
                'has_button' => true,
                'has_upload' => false,
            ],
            SyncStatus::SOURCE_RAIDERIO => [
                'label' => 'Raider.IO',
                'description' => 'Per-character raid progression + M+ scores for every active member. Roster-flexible (covers heroic + mythic teams).',
                'last_seen_at' => $rio?->captured_at,
                'last_summary' => $rio ? "{$rio->member_count} members" : null,
                'state' => SyncStatus::get(SyncStatus::SOURCE_RAIDERIO),
                'cadence' => 'Pull: twice daily (07:00 + 18:00 UK).',
                'has_button' => true,
                'has_upload' => false,
            ],
            SyncStatus::SOURCE_BLIZZARD => [
                'label' => 'Battle.net (Blizzard)',
                'description' => 'Authoritative source for both guild roster (who is in the guild + character IDs) and per-character profile (gear, ilvl). One click runs both: roster first to upsert new members, then profile fan-out for ilvl. GRM still owns notes, alts, join dates and officer flags.',
                'last_seen_at' => $bnet?->captured_at,
                'last_summary' => $bnet ? "{$bnet->member_count} members" : null,
                'state' => SyncStatus::get(SyncStatus::SOURCE_BLIZZARD),
                'cadence' => 'Pull: roster daily 06:45 UK, profile twice-daily (07:00 + 18:00 UK). Manual button runs both.',
                'has_button' => true,
                'has_upload' => false,
            ],
            SyncStatus::SOURCE_WCL => [
                'label' => 'Warcraft Logs',
                'description' => 'Per-raid-night reports from Warcraft Logs (title, zone, time). Per-encounter parses ship in a follow-up.',
                'last_seen_at' => $wcl?->captured_at,
                'last_summary' => $wcl ? "{$wclTotal} reports stored / latest: {$wcl->title}" : null,
                'state' => SyncStatus::get(SyncStatus::SOURCE_WCL),
                'cadence' => 'Pull: daily 07:30 UK.',
                'has_button' => true,
                'has_upload' => false,
            ],
        ];

        // Drive meta-refresh from server-side state so a click is
        // immediately reflected in "this page is currently syncing".
        $autoRefresh = collect($sources)->contains(fn ($s) => SyncStatus::isInProgress($s['state']));

        return view('admin.sync.index', [
            'sources' => $sources,
            'autoRefresh' => $autoRefresh,
        ]);
    }

    public function uploadGrm(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->isOfficerTier(), 403);
        $back = redirect()->route('admin.sync.index');

        // When the request body exceeds post_max_size, PHP throws away
        // $_POST and $_FILES wholesale. The CSRF middleware would normally
        // 419 first, but if it slipped through (or for upload_max_filesize
        // overruns where only the file is dropped), we want a clear hint
        // instead of Laravel's generic "field is required".
        $rawFile = $_FILES['grm_file'] ?? null;
        if (is_array($rawFile) && (int) ($rawFile['error'] ?? 0) !== UPLOAD_ERR_OK) {
            $hint = $this->describeUploadError((int) $rawFile['error']);
            return $back->withErrors(['grm_upload' => $hint]);
        }
        if (! $request->hasFile('grm_file')) {
            $contentLength = (int) $request->server('CONTENT_LENGTH', 0);
            $postMax = $this->iniBytes('post_max_size');
            if ($contentLength > 0 && $postMax > 0 && $contentLength > $postMax) {
                return $back->withErrors(['grm_upload' => sprintf(
                    'The upload was %s but PHP post_max_size is %s. Raise post_max_size and upload_max_filesize in php.ini (Hostinger: hPanel > Advanced > PHP Configuration).',
                    $this->humanBytes($contentLength),
                    ini_get('post_max_size'),
                )]);
            }
            return $back->withErrors(['grm_upload' => 'No file was received. If you definitely picked a file, the request body may have exceeded PHP\'s post_max_size; raise post_max_size + upload_max_filesize and try again.']);
        }

        $validated = $request->validate([
            // 50MB cap is generous for a SavedVariables.lua; the real
            // ones tend to be 5-15MB. PHP's upload_max_filesize and
            // post_max_size also need to allow this.
            'grm_file' => ['required', 'file', 'max:51200'],
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['grm_file'];
        $contents = $file->get();
        $fileName = $file->getClientOriginalName();
        $fileSize = is_string($contents) ? strlen($contents) : 0;
        if ($contents === '' || $contents === false) {
            return $back->withErrors(['grm_upload' => 'The uploaded file was empty.']);
        }

        $startedAt = now();
        $userId = auth()->id();

        $write = static function (string $status, ?string $stage, array $summary, ?string $error) use ($startedAt, $userId): void {
            SyncStatus::set(SyncStatus::SOURCE_GRM, [
                'status' => $status,
                'stage' => $stage,
                'started_at' => $startedAt->toIso8601String(),
                'started_by_user_id' => $userId,
                'finished_at' => in_array($status, [SyncStatus::DONE, SyncStatus::FAILED], true)
                    ? now()->toIso8601String() : null,
                'summary' => $summary,
                'error' => $error,
            ]);
        };

        $summary = [
            'file_name' => $fileName,
            'file_size_kb' => (int) round($fileSize / 1024),
        ];

        $write(SyncStatus::RUNNING, 'parsing', $summary, null);

        $parseStart = microtime(true);
        try {
            // GRM globals we actually consume. Anything else in the file
            // (Recount, Details, etc.) is skipped so the parser doesn't
            // waste time on tables we'd discard later.
            $payload = (new LuaTableParser())->parse($contents, only: [
                'GRM_GuildMemberHistory_Save',
                'GRM_PlayersThatLeftHistory_Save',
                'GRM_Alts',
                'GRM_LogReport_Save',
                'GRM_AddonSettings_Save',
            ]);
        } catch (\Throwable $e) {
            $write(SyncStatus::FAILED, 'parsing', $summary, 'Lua parse failed: ' . $e->getMessage());
            return $back->withErrors(['grm_upload' => 'Could not parse the .lua file. Make sure it is the GRM SavedVariables file (Guild_Roster_Manager.lua).']);
        }

        $guildKey = (string) config('grm.guild_key');
        $currentRows = $payload['GRM_GuildMemberHistory_Save'][$guildKey] ?? [];
        $formerRows = $payload['GRM_PlayersThatLeftHistory_Save'][$guildKey] ?? [];
        $altRows = $payload['GRM_Alts'][$guildKey] ?? [];
        $logRows = $payload['GRM_LogReport_Save'][$guildKey] ?? [];

        $grmVersion = is_string($payload['GRM_AddonSettings_Save']['version'] ?? null)
            ? $payload['GRM_AddonSettings_Save']['version']
            : null;

        $summary = array_merge($summary, [
            'parse_ms' => (int) round((microtime(true) - $parseStart) * 1000),
            'grm_version' => $grmVersion,
            'current_rows' => is_array($currentRows) ? count($currentRows) : 0,
            'former_rows' => is_array($formerRows) ? count($formerRows) : 0,
            'file_alt_groups' => is_array($altRows) ? count($altRows) : 0,
            'log_rows_in_file' => is_array($logRows) ? count($logRows) : 0,
        ]);

        $write(SyncStatus::RUNNING, 'saving', $summary, null);

        try {
            $result = (new GrmSnapshotIngester())->ingest(
                guildKey: $guildKey,
                payload: $payload,
                grmVersion: $grmVersion,
            );
        } catch (\Throwable $e) {
            $write(SyncStatus::FAILED, 'saving', $summary, 'Ingest failed: ' . $e->getMessage());
            return $back->withErrors(['grm_upload' => 'Saving the snapshot failed: ' . $e->getMessage()]);
        }

        $summary = array_merge($summary, [
            'snapshot_id' => $result['snapshot_id'],
            'was_duplicate' => $result['was_duplicate'],
        ]);

        if ($result['was_duplicate']) {
            $write(SyncStatus::DONE, 'duplicate', $summary, null);
            return $back->with('status', "Upload received but it matches an existing snapshot (#{$result['snapshot_id']}) - the dashboard already has this data. Nothing new to ingest.");
        }

        // Run the normalize + diff inline so the officer sees the full
        // result on the same redirect. The job updates SyncStatus as it
        // progresses (normalizing -> diffing -> done), so the staged
        // breadcrumb survives even on this synchronous path. If the job
        // throws, it writes FAILED itself; we still redirect to /admin/sync
        // so the user sees the error in the panel.
        try {
            IngestSnapshotJob::dispatchSync($result['snapshot_id']);
        } catch (\Throwable $e) {
            // Job already wrote FAILED state; just surface to flash too.
            return $back->withErrors(['grm_upload' => 'Snapshot saved (#' . $result['snapshot_id'] . ") but processing failed: " . $e->getMessage()]);
        }

        // Bus::fake() in tests short-circuits dispatchSync without running
        // the job, leaving the cached state stuck on RUNNING. If we're
        // still mid-flight after dispatchSync returned, mark DONE here so
        // the dashboard reflects what the controller knows.
        $postDispatchState = SyncStatus::get(SyncStatus::SOURCE_GRM);
        if (! in_array($postDispatchState['status'] ?? null, [SyncStatus::DONE, SyncStatus::FAILED], true)) {
            $write(SyncStatus::DONE, 'done', $summary, null);
        }

        $finalState = SyncStatus::get(SyncStatus::SOURCE_GRM);
        $finalSummary = $finalState['summary'] ?? $summary;
        $msg = $this->buildUploadFlash($result['snapshot_id'], $finalSummary);

        return $back->with('status', $msg);
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function buildUploadFlash(int $snapshotId, array $summary): string
    {
        $bits = ["GRM snapshot #{$snapshotId} imported"];

        if (isset($summary['members_ingested'])) {
            $bits[] = "{$summary['members_ingested']} members";
        }
        if (isset($summary['log_events_added'])) {
            $bits[] = "{$summary['log_events_added']} new log entries";
        }
        if (isset($summary['change_events_emitted'])) {
            $bits[] = "{$summary['change_events_emitted']} change events";
        }

        if (count($bits) === 1) {
            return $bits[0] . '. (Processing details will appear in the panel below.)';
        }

        $head = array_shift($bits);
        return $head . ': ' . implode(', ', $bits) . '.';
    }

    private function describeUploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE => sprintf(
                'The uploaded file is bigger than PHP\'s upload_max_filesize (%s). Raise upload_max_filesize + post_max_size and try again.',
                ini_get('upload_max_filesize') ?: '?',
            ),
            UPLOAD_ERR_FORM_SIZE => 'The file exceeded the form\'s MAX_FILE_SIZE limit.',
            UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded. Try again, ideally on a stable connection.',
            UPLOAD_ERR_NO_FILE => 'No file was sent. Pick a file and click Upload again.',
            UPLOAD_ERR_NO_TMP_DIR => 'PHP has no temp directory configured for uploads. Ask hosting support.',
            UPLOAD_ERR_CANT_WRITE => 'PHP could not write the uploaded file to disk. Ask hosting support.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension blocked the upload.',
            default => 'Upload failed (PHP error code ' . $code . ').',
        };
    }

    private function iniBytes(string $key): int
    {
        $raw = (string) ini_get($key);
        if ($raw === '') {
            return 0;
        }
        $unit = strtolower(substr($raw, -1));
        $num = (int) $raw;
        return match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => $num,
        };
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / 1024 / 1024, 1) . 'M';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024) . 'K';
        }
        return $bytes . 'B';
    }

    private function latestSnapshot(string $guildKey, string $source): ?Snapshot
    {
        return Snapshot::query()
            ->where('guild_key', $guildKey)
            ->where('source', $source)
            ->latest('captured_at')
            ->first();
    }
}
