<?php

namespace App\Services\GoogleCalendar;

use App\Models\RaidEvent;
use Carbon\CarbonImmutable;

/**
 * Translates a RaidEvent into the JSON body Google's Calendar API expects
 * for events.insert / events.patch. Field set mirrors what IcsBuilder
 * already publishes (summary, description with the Discord jump URL,
 * start/end with the configured timezone) so the Google copy and the
 * ICS copy stay in lockstep.
 *
 * Includes extendedProperties.private.regenesis_event_id so the daily
 * reconcile cron can map Google rows back to local rows without trusting
 * summary or title (officers can rename events on the Google side).
 */
class GoogleEventBodyBuilder
{
    /**
     * @return array<string,mixed>
     */
    public function build(RaidEvent $event): array
    {
        $tz = (string) config('services.google_calendar.timezone', config('raidhelper.timezone', 'Europe/Paris'));
        $start = $event->starts_at instanceof CarbonImmutable
            ? $event->starts_at->setTimezone($tz)
            : CarbonImmutable::parse((string) $event->starts_at, 'UTC')->setTimezone($tz);
        $end = $event->ends_at
            ? ($event->ends_at instanceof CarbonImmutable
                ? $event->ends_at->setTimezone($tz)
                : CarbonImmutable::parse((string) $event->ends_at, 'UTC')->setTimezone($tz))
            : $start->addHours(2);

        $description = trim(($event->description ?? '')."\n\n".$event->discordJumpUrl());

        return [
            'summary' => $event->title,
            'description' => $description,
            'start' => [
                'dateTime' => $start->toIso8601String(),
                'timeZone' => $tz,
            ],
            'end' => [
                'dateTime' => $end->toIso8601String(),
                'timeZone' => $tz,
            ],
            // CONFIRMED matches the ICS feed; Google never auto-cancels
            // an event we own so this stays stable across upserts. Soft
            // deletes call deleteEvent, not a status flip.
            'status' => 'confirmed',
            'transparency' => 'opaque',
            'source' => [
                'title' => 'Regenesis dashboard',
                'url' => $event->discordJumpUrl(),
            ],
            // Reconciliation looks up local rows by these. The id is the
            // local primary key; the sequence is a cheap drift indicator
            // for spotting an out-of-date Google copy.
            'extendedProperties' => [
                'private' => [
                    'regenesis_event_id' => (string) $event->id,
                    'regenesis_ics_sequence' => (string) (int) $event->ics_sequence,
                ],
            ],
            // We're not driving RSVPs through Google. The ICS feed and
            // Discord are the source-of-truth surfaces. Keeping reminders
            // off avoids surprise pings on officers who already get the
            // Discord pre-raid announcements.
            'reminders' => [
                'useDefault' => false,
                'overrides' => [],
            ],
        ];
    }
}
