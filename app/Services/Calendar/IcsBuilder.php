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

    /**
     * Combined social feed: raid events + world events (Darkmoon Faire,
     * holidays, Trading Post resets) in one calendar so a single
     * subscription gives the full Social-page picture.
     *
     * World events are date-grain (24-hour boundaries) so they're added
     * with date-only DTSTART/DTEND, which calendar clients render as
     * multi-day banner rows rather than timed slots.
     *
     * @param  iterable<RaidEvent>  $raidEvents
     * @param  list<array{name:string, starts_at:CarbonImmutable, ends_at:CarbonImmutable, kind:string, tone:string, description:?string}>  $worldEvents
     */
    public function buildSocialFeed(iterable $raidEvents, array $worldEvents): string
    {
        $cal = $this->newCalendar('Regenesis Social');
        foreach ($raidEvents as $e) {
            $this->addEvent($cal, $e);
        }
        foreach ($worldEvents as $w) {
            $this->addWorldEvent($cal, $w);
        }
        return (string) $cal->serialize();
    }

    private function newCalendar(string $name = 'Regenesis Raid Events'): VCalendar
    {
        return new VCalendar([
            'PRODID' => '-//Regenesis//Guild Dashboard//EN',
            'VERSION' => '2.0',
            'CALSCALE' => 'GREGORIAN',
            'METHOD' => 'PUBLISH',
            'X-WR-CALNAME' => $name,
            'X-WR-TIMEZONE' => config('raidhelper.timezone', 'Europe/Paris'),
        ]);
    }

    /**
     * @param  array{name:string, starts_at:CarbonImmutable, ends_at:CarbonImmutable, kind:string, tone:string, description:?string}  $event
     */
    private function addWorldEvent(VCalendar $cal, array $event): void
    {
        // Stable per-event UID anchored on (name, year, month, day) so
        // re-fetching the same calendar produces the same identity.
        $uidSeed = sprintf(
            '%s|%s',
            $event['name'],
            $event['starts_at']->format('Ymd'),
        );
        $uid = 'world-' . hash('sha256', $uidSeed) . '@regenesis';

        // Date-only events: ICS expects DTSTART;VALUE=DATE for all-day
        // banner rendering. DTEND on date-only events is exclusive
        // (https://www.rfc-editor.org/rfc/rfc5545#section-3.6.1) so we
        // bump the actual end day by one.
        $startDate = $event['starts_at']->format('Ymd');
        $endDateExclusive = $event['ends_at']->copy()->addDay()->format('Ymd');

        $vevent = $cal->add('VEVENT', [
            'UID' => $uid,
            'DTSTAMP' => CarbonImmutable::now('UTC'),
            'SUMMARY' => $event['name'],
            'DESCRIPTION' => (string) ($event['description'] ?? ''),
            'STATUS' => 'CONFIRMED',
            'CATEGORIES' => 'World event',
            'TRANSP' => 'TRANSPARENT',  // doesn't block availability
        ]);
        $vevent->add('DTSTART', $startDate, ['VALUE' => 'DATE']);
        $vevent->add('DTEND', $endDateExclusive, ['VALUE' => 'DATE']);
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
