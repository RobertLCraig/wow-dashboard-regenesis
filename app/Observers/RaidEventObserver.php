<?php

namespace App\Observers;

use App\Jobs\SyncEventToGoogleJob;
use App\Models\RaidEvent;

/**
 * Single chokepoint for keeping the shared Google Calendar in sync with
 * raid_events. Every existing write path goes through Eloquent on this
 * model (officer create via EventController, officer destroy, the four
 * Raid-Helper webhook receivers, the daily raidhelper:sync-events
 * backfill), so observing here catches them all without scattering
 * dispatches.
 *
 * The job itself short-circuits when no officer is connected and writes
 * a visible SyncStatus row, so dispatching unconditionally is safe and
 * keeps the observer's logic simple.
 *
 * The "material fields" check exists to avoid a job per signup-array
 * touch (each signup write fires a saved() event but does not need a
 * Google update). Mirrors the same field set EventUpserter uses to
 * decide whether to bump ics_sequence, so the Google copy and the ICS
 * copy stay in lockstep.
 */
class RaidEventObserver
{
    private const MATERIAL_FIELDS = [
        'title',
        'description',
        'starts_at',
        'ends_at',
        'channel_id',
    ];

    public function saved(RaidEvent $event): void
    {
        if ($event->wasRecentlyCreated || $this->materialFieldsChanged($event)) {
            SyncEventToGoogleJob::dispatch($event->id, SyncEventToGoogleJob::ACTION_UPSERT);
        }
    }

    public function deleted(RaidEvent $event): void
    {
        SyncEventToGoogleJob::dispatch($event->id, SyncEventToGoogleJob::ACTION_DELETE);
    }

    public function restored(RaidEvent $event): void
    {
        SyncEventToGoogleJob::dispatch($event->id, SyncEventToGoogleJob::ACTION_UPSERT);
    }

    private function materialFieldsChanged(RaidEvent $event): bool
    {
        foreach (self::MATERIAL_FIELDS as $field) {
            if ($event->wasChanged($field)) {
                return true;
            }
        }

        return false;
    }
}
