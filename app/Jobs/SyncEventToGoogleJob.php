<?php

namespace App\Jobs;

use App\Models\RaidEvent;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarClient;
use App\Services\Sync\SyncStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Per-event push to the shared Google Calendar. Dispatched from
 * RaidEventObserver on saved/restored/deleted, and from the daily
 * reconcile cron when it spots drift.
 *
 * No-ops cleanly (with visible state) when:
 *   - GOOGLE_CALENDAR_CLIENT_* are unset (config not configured)
 *   - No officer is connected (User::googleConnector() returns null)
 *   - The local row vanished between dispatch and handle()
 *
 * Each of those branches writes a SyncStatus FAILED row with the
 * specific reason, per the user's "nothing fails silently" rule.
 *
 * Retries: up to 3 attempts with exponential backoff. On final failure
 * the SyncStatus FAILED row remains, surfaced on /admin/sync.
 */
class SyncEventToGoogleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const ACTION_UPSERT = 'upsert';

    public const ACTION_DELETE = 'delete';

    public int $tries = 3;

    public function __construct(
        public readonly int $raidEventId,
        public readonly string $action,
    ) {}

    /** @return array<int,int> */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(): void
    {
        // Push past Hostinger's 30s default. Per-call HTTP timeout in
        // GoogleCalendarClient is the real bound (20s).
        @set_time_limit(120);

        $startedAt = now()->toIso8601String();

        $client = GoogleCalendarClient::fromConfig();
        if (! $client->isConfigured()) {
            $this->writeFailed($startedAt, 'GOOGLE_CALENDAR_CLIENT_ID / _SECRET / _REDIRECT_URI are not set; sync is disabled.');
            Log::info('SyncEventToGoogleJob skipped: not configured', [
                'event_id' => $this->raidEventId,
                'action' => $this->action,
            ]);

            return;
        }

        $user = User::googleConnector();
        if ($user === null) {
            $this->writeFailed($startedAt, 'No officer is connected to Google Calendar. Visit /admin/google-calendar to connect.');
            Log::info('SyncEventToGoogleJob skipped: no connector', [
                'event_id' => $this->raidEventId,
                'action' => $this->action,
            ]);

            return;
        }

        // withTrashed: a soft-deleted row needs the delete action to
        // resolve its google_calendar_event_id. Eloquent excludes
        // trashed by default.
        $event = RaidEvent::withTrashed()->find($this->raidEventId);
        if ($event === null) {
            // Hard-deleted between dispatch and handle. Nothing we can
            // do (we don't know the Google event id any more) but
            // surface so the failure is visible.
            $this->writeFailed($startedAt, "RaidEvent {$this->raidEventId} no longer exists; cannot sync to Google.");
            Log::warning('SyncEventToGoogleJob: event vanished', [
                'event_id' => $this->raidEventId,
                'action' => $this->action,
            ]);

            return;
        }

        try {
            $googleEventId = match ($this->action) {
                self::ACTION_UPSERT => $this->doUpsert($client, $user, $event),
                self::ACTION_DELETE => $this->doDelete($client, $user, $event),
                default => throw new \InvalidArgumentException("Unknown action: {$this->action}"),
            };

            SyncStatus::set(SyncStatus::SOURCE_GOOGLE_CAL, [
                'status' => SyncStatus::DONE,
                'started_at' => $startedAt,
                'finished_at' => now()->toIso8601String(),
                'started_by_user_id' => null,
                'summary' => [
                    'event_id' => $event->id,
                    'action' => $this->action,
                    'google_event_id' => $googleEventId,
                    'title' => $event->title,
                ],
                'error' => null,
            ]);
            Log::info('SyncEventToGoogleJob ok', [
                'event_id' => $event->id,
                'action' => $this->action,
                'google_event_id' => $googleEventId,
            ]);
        } catch (\Throwable $e) {
            $this->writeFailed($startedAt, $e->getMessage(), $event->id);
            Log::warning('SyncEventToGoogleJob failed', [
                'event_id' => $event->id,
                'action' => $this->action,
                'message' => $e->getMessage(),
            ]);
            // Re-throw so Laravel's queue retry kicks in. After tries=3
            // the SyncStatus FAILED row stays put for the admin UI.
            throw $e;
        }
    }

    private function doUpsert(GoogleCalendarClient $client, User $user, RaidEvent $event): string
    {
        if ($event->trashed()) {
            // Race: the row was soft-deleted between observer dispatch
            // and handle(). Switch to delete so we don't push a stale
            // copy to Google.
            return $this->doDelete($client, $user, $event);
        }
        $newId = $client->upsertEvent($user, $event);
        if ($event->google_calendar_event_id !== $newId) {
            $event->google_calendar_event_id = $newId;
            // saveQuietly so the observer's saved() hook doesn't fire
            // and re-dispatch this same job on the column write.
            $event->saveQuietly();
        }

        return $newId;
    }

    private function doDelete(GoogleCalendarClient $client, User $user, RaidEvent $event): string
    {
        $existing = $event->google_calendar_event_id;
        if (! is_string($existing) || $existing === '') {
            // Nothing on Google to delete; idempotent success.
            return '';
        }
        $client->deleteEvent($user, $existing);
        $event->google_calendar_event_id = null;
        $event->saveQuietly();

        return $existing;
    }

    private function writeFailed(string $startedAt, string $error, ?int $eventId = null): void
    {
        SyncStatus::set(SyncStatus::SOURCE_GOOGLE_CAL, [
            'status' => SyncStatus::FAILED,
            'started_at' => $startedAt,
            'finished_at' => now()->toIso8601String(),
            'started_by_user_id' => null,
            'summary' => [
                'event_id' => $eventId ?? $this->raidEventId,
                'action' => $this->action,
            ],
            'error' => $error,
        ]);
    }
}
