<?php

namespace App\Services\Calendar;

use App\Models\RaidEvent;
use Carbon\CarbonImmutable;
use Sabre\VObject\Component\VCalendar;

/**
 * Builds RFC 5545-compliant iCalendar text for one or many RaidEvents
 * via sabre/vobject.
 *
 * Stable UID per event (raid_events.ics_uid, generated once on first
 * upsert). SEQUENCE bumped on material edits (handled in EventUpserter)
 * so calendar clients refresh. METHOD:PUBLISH is right for one-way
 * publishing (we don't expect attendees to RSVP through .ics).
 */
class IcsBuilder
{
    /** @return string text/calendar body */
    public function buildOne(RaidEvent $event): string
    {
        $cal = $this->newCalendar();
        $this->addEvent($cal, $event);
        return (string) $cal->serialize();
    }

    /**
     * @param  iterable<RaidEvent>  $events
     */
    public function buildFeed(iterable $events): string
    {
        $cal = $this->newCalendar();
        foreach ($events as $e) {
            $this->addEvent($cal, $e);
        }
        return (string) $cal->serialize();
    }

    private function newCalendar(): VCalendar
    {
        return new VCalendar([
            'PRODID' => '-//Regenesis//Guild Dashboard//EN',
            'VERSION' => '2.0',
            'CALSCALE' => 'GREGORIAN',
            'METHOD' => 'PUBLISH',
            'X-WR-CALNAME' => 'Regenesis Raid Events',
            'X-WR-TIMEZONE' => config('raidhelper.timezone', 'Europe/Paris'),
        ]);
    }

    private function addEvent(VCalendar $cal, RaidEvent $event): void
    {
        $tz = config('raidhelper.timezone', 'Europe/Paris');
        $start = $event->starts_at instanceof CarbonImmutable
            ? $event->starts_at->setTimezone($tz)
            : CarbonImmutable::parse((string) $event->starts_at, 'UTC')->setTimezone($tz);
        $end = $event->ends_at
            ? ($event->ends_at instanceof CarbonImmutable ? $event->ends_at->setTimezone($tz) : CarbonImmutable::parse((string) $event->ends_at, 'UTC')->setTimezone($tz))
            : $start->addHours(2);

        $description = trim(($event->description ?? '') . "\n\n" . $event->discordJumpUrl());

        $cal->add('VEVENT', [
            'UID' => $event->ics_uid,
            'SEQUENCE' => (int) $event->ics_sequence,
            'DTSTAMP' => CarbonImmutable::now('UTC'),
            'DTSTART' => $start,
            'DTEND' => $end,
            'SUMMARY' => $event->title,
            'DESCRIPTION' => $description,
            'URL' => $event->discordJumpUrl(),
            'STATUS' => $event->trashed() ? 'CANCELLED' : 'CONFIRMED',
        ]);
    }
}
