<?php

namespace App\Console\Commands;

use App\Jobs\SyncEventToGoogleJob;
use App\Models\RaidEvent;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarClient;
use App\Services\Sync\SyncStatus;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Daily safety net for the per-event push. Walks raid_events in the
 * rolling feed window and the Google calendar's contents in the same
 * window, dispatches a per-event upsert/delete job for any drift
 * detected.
 *
 * Detects:
 *   - Local row that has no google_calendar_event_id (push job lost
 *     between dispatch and Google's API), or whose stored google id
 *     doesn't appear in the calendar (Google-side delete)
 *     -> dispatch ACTION_UPSERT
 *   - Google event tagged regenesis_event_id pointing to a row that
 *     no longer exists or is soft-deleted (push job for the delete
 *     never landed)
 *     -> dispatch ACTION_DELETE
 *
 * Stays well under Hostinger's 30s PHP cap because the cron itself
 * only enumerates: every actual API write happens inside the queue
 * worker via SyncEventToGoogleJob.
 */
class SyncGoogleCalendarReconcile extends Command
{
    protected $signature = 'google-calendar:reconcile {--dry-run}';

    protected $description = 'Walk raid_events vs the shared Google Calendar and dispatch sync jobs for any drift.';

    public function handle(): int
    {
        $startedAt = now()->toIso8601String();
        $dryRun = (bool) $this->option('dry-run');

        $client = GoogleCalendarClient::fromConfig();
        if (! $client->isConfigured()) {
            $this->writeFailed($startedAt, 'GOOGLE_CALENDAR_CLIENT_ID / _SECRET / _REDIRECT_URI are not set; reconcile skipped.');
            $this->warn('Google Calendar OAuth is not configured; nothing to reconcile.');

            // Return SUCCESS so the cron stays armed; the failure shows
            // up in /admin/sync's Google Calendar panel, not as a cron
            // alert (this is expected pre-setup state).
            return self::SUCCESS;
        }

        $connector = User::googleConnector();
        if ($connector === null) {
            $this->writeFailed($startedAt, 'No officer is connected to Google Calendar; reconcile skipped.');
            $this->warn('No officer is connected to Google Calendar; nothing to reconcile.');

            return self::SUCCESS;
        }

        $from = CarbonImmutable::now()->subDays(7);
        $to = CarbonImmutable::now()->addDays(90);

        try {
            $googleItems = $client->listEvents($connector, $from, $to);
        } catch (\Throwable $e) {
            $this->writeFailed($startedAt, 'List events failed: '.$e->getMessage());
            $this->error('Google list events failed: '.$e->getMessage());
            Log::warning('GoogleCalendar reconcile: list failed', ['message' => $e->getMessage()]);

            return self::FAILURE;
        }

        // Index Google events by regenesis_event_id for the local-side
        // walk, and keep the full list for the orphan walk.
        $googleByLocalId = [];
        foreach ($googleItems as $item) {
            $localId = $item['regenesis_event_id'];
            if ($localId !== null && ctype_digit($localId)) {
                $googleByLocalId[(int) $localId][] = $item;
            }
        }

        $localEvents = RaidEvent::query()
            ->withinFeedWindow()
            ->select(['id', 'google_calendar_event_id', 'title'])
            ->orderBy('starts_at')
            ->get();

        $upsertsQueued = 0;
        $deletesQueued = 0;

        foreach ($localEvents as $event) {
            $stored = $event->google_calendar_event_id;
            $googleSeen = $googleByLocalId[$event->id] ?? [];
            $needsUpsert = false;

            if (! is_string($stored) || $stored === '') {
                // Never been synced (or column was nulled by reconcile
                // after a Google-side delete). Push it.
                $needsUpsert = true;
            } else {
                // Confirm Google still has the event we think it does.
                $found = false;
                foreach ($googleSeen as $g) {
                    if ($g['google_id'] === $stored) {
                        $found = true;
                        break;
                    }
                }
                if (! $found) {
                    $needsUpsert = true;
                }
            }

            if ($needsUpsert) {
                if (! $dryRun) {
                    SyncEventToGoogleJob::dispatch($event->id, SyncEventToGoogleJob::ACTION_UPSERT);
                }
                $upsertsQueued++;
                $this->line("  + queue upsert event {$event->id} ({$event->title})");
            }
        }

        // Orphan walk: Google events tagged with our local id but the
        // local row is gone or soft-deleted.
        $localKnownIds = $localEvents->pluck('id')->all();
        foreach ($googleItems as $item) {
            $localId = $item['regenesis_event_id'];
            if ($localId === null || ! ctype_digit($localId)) {
                // Untagged Google events aren't ours; leave them alone.
                continue;
            }
            $intId = (int) $localId;
            if (in_array($intId, $localKnownIds, true)) {
                continue;
            }

            // Does the row exist outside the feed window or was it
            // soft-deleted? withTrashed catches both. If it's gone
            // entirely, dispatch a delete; the job knows how to no-op
            // when the row has vanished.
            $row = RaidEvent::withTrashed()->find($intId);
            if ($row === null || $row->trashed()) {
                if (! $dryRun) {
                    SyncEventToGoogleJob::dispatch($intId, SyncEventToGoogleJob::ACTION_DELETE);
                }
                $deletesQueued++;
                $this->line("  - queue delete google event {$item['google_id']} (local row #{$intId} gone)");
            }
        }

        $summary = [
            'window_from' => $from->toIso8601String(),
            'window_to' => $to->toIso8601String(),
            'local_events' => $localEvents->count(),
            'google_events' => count($googleItems),
            'upserts_queued' => $upsertsQueued,
            'deletes_queued' => $deletesQueued,
            'dry_run' => $dryRun,
        ];

        SyncStatus::set(SyncStatus::SOURCE_GOOGLE_CAL, [
            'status' => SyncStatus::DONE,
            'started_at' => $startedAt,
            'finished_at' => now()->toIso8601String(),
            'started_by_user_id' => null,
            'summary' => $summary,
            'error' => null,
        ]);

        Log::info('GoogleCalendar reconcile complete', $summary);

        $this->info(sprintf(
            'Reconcile complete: %d local / %d google in window, queued %d upsert(s) and %d delete(s)%s.',
            $summary['local_events'],
            $summary['google_events'],
            $upsertsQueued,
            $deletesQueued,
            $dryRun ? ' (dry-run, nothing dispatched)' : '',
        ));

        return self::SUCCESS;
    }

    private function writeFailed(string $startedAt, string $error): void
    {
        SyncStatus::set(SyncStatus::SOURCE_GOOGLE_CAL, [
            'status' => SyncStatus::FAILED,
            'started_at' => $startedAt,
            'finished_at' => now()->toIso8601String(),
            'started_by_user_id' => null,
            'summary' => null,
            'error' => $error,
        ]);
    }
}
