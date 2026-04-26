<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Snapshot;
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

        $sources = [
            SyncStatus::SOURCE_GRM => [
                'label' => 'GRM (in-game)',
                'description' => 'In-game Guild_Roster_Manager addon. Pushed from your PC by the PowerShell sync tool every 30 minutes; you can also drop a SavedVariables.lua file here for an immediate import.',
                'snapshot' => $this->latestSnapshot($guildKey, Snapshot::SOURCE_GRM),
                'state' => SyncStatus::get(SyncStatus::SOURCE_GRM),
                'cadence' => 'Push: every 30 min from your PC.',
                'has_button' => false,
                'has_upload' => true,
            ],
            SyncStatus::SOURCE_RAIDHELPER => [
                'label' => 'Raid-Helper',
                'description' => 'Discord raid signups. Real-time push via webhook + a daily safety-net pull.',
                'snapshot' => null,
                'state' => SyncStatus::get(SyncStatus::SOURCE_RAIDHELPER),
                'cadence' => 'Push: webhook on event create/update/delete. Pull: daily 06:15 UK.',
                'has_button' => false,
                'has_upload' => false,
            ],
            SyncStatus::SOURCE_WOWAUDIT => [
                'label' => 'Wowaudit',
                'description' => 'Mythic team\'s vault + ilvl tracker. Pulled hourly from wowaudit.com.',
                'snapshot' => $this->latestSnapshot($guildKey, Snapshot::SOURCE_WOWAUDIT),
                'state' => SyncStatus::get(SyncStatus::SOURCE_WOWAUDIT),
                'cadence' => 'Pull: hourly.',
                'has_button' => true,
                'has_upload' => false,
            ],
            SyncStatus::SOURCE_RAIDERIO => [
                'label' => 'Raider.IO',
                'description' => 'Per-character raid progression + M+ scores for every active member. Roster-flexible (covers heroic + mythic teams).',
                'snapshot' => $this->latestSnapshot($guildKey, Snapshot::SOURCE_RAIDERIO),
                'state' => SyncStatus::get(SyncStatus::SOURCE_RAIDERIO),
                'cadence' => 'Pull: twice daily (07:00 + 18:00 UK).',
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

        $validated = $request->validate([
            // 50MB cap is generous for a SavedVariables.lua; the real
            // ones tend to be 5-15MB. PHP's upload_max_filesize and
            // post_max_size also need to allow this.
            'grm_file' => ['required', 'file', 'max:51200'],
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['grm_file'];
        $contents = $file->get();
        if ($contents === '' || $contents === false) {
            return $back->withErrors(['grm_upload' => 'The uploaded file was empty.']);
        }

        $guildKey = (string) config('grm.guild_key');

        SyncStatus::set(SyncStatus::SOURCE_GRM, [
            'status' => SyncStatus::RUNNING,
            'started_at' => now()->toIso8601String(),
            'started_by_user_id' => auth()->id(),
            'finished_at' => null,
            'summary' => null,
            'error' => null,
        ]);

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
            SyncStatus::set(SyncStatus::SOURCE_GRM, [
                'status' => SyncStatus::FAILED,
                'started_at' => SyncStatus::get(SyncStatus::SOURCE_GRM)['started_at'] ?? now()->toIso8601String(),
                'started_by_user_id' => auth()->id(),
                'finished_at' => now()->toIso8601String(),
                'summary' => null,
                'error' => 'Lua parse failed: ' . $e->getMessage(),
            ]);
            return $back->withErrors(['grm_upload' => 'Could not parse the .lua file. Make sure it is the GRM SavedVariables file (Guild_Roster_Manager.lua).']);
        }

        $grmVersion = is_string($payload['GRM_AddonSettings_Save']['version'] ?? null)
            ? $payload['GRM_AddonSettings_Save']['version']
            : null;

        try {
            $result = (new GrmSnapshotIngester())->ingest(
                guildKey: $guildKey,
                payload: $payload,
                grmVersion: $grmVersion,
            );
        } catch (\Throwable $e) {
            SyncStatus::set(SyncStatus::SOURCE_GRM, [
                'status' => SyncStatus::FAILED,
                'started_at' => SyncStatus::get(SyncStatus::SOURCE_GRM)['started_at'] ?? now()->toIso8601String(),
                'started_by_user_id' => auth()->id(),
                'finished_at' => now()->toIso8601String(),
                'summary' => null,
                'error' => 'Ingest failed: ' . $e->getMessage(),
            ]);
            return $back->withErrors(['grm_upload' => 'Saving the snapshot failed: ' . $e->getMessage()]);
        }

        SyncStatus::set(SyncStatus::SOURCE_GRM, [
            'status' => SyncStatus::DONE,
            'started_at' => SyncStatus::get(SyncStatus::SOURCE_GRM)['started_at'] ?? now()->toIso8601String(),
            'started_by_user_id' => auth()->id(),
            'finished_at' => now()->toIso8601String(),
            'summary' => [
                'snapshot_id' => $result['snapshot_id'],
                'was_duplicate' => $result['was_duplicate'],
            ],
            'error' => null,
        ]);

        $msg = $result['was_duplicate']
            ? 'GRM upload accepted but matches an existing snapshot - no new data to ingest.'
            : "GRM snapshot #{$result['snapshot_id']} saved. Normalization runs in the background.";

        return $back->with('status', $msg);
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
