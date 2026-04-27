<?php

namespace App\Services\WorldEvents;

use Carbon\CarbonImmutable;

/**
 * Computes recurring in-game world events that fall inside a date
 * range. Phase 1 covers Darkmoon Faire because its cadence is fixed
 * and predictable; future tiers can add Brewfest, Hallow's End, the
 * Trading Post reset, weekly maintenance windows, and so on - they're
 * all date math against an absolute calendar, no API needed.
 *
 * Returned shape matches what the SocialController unifies with
 * Raid-Helper events:
 *   ['name','starts_at','ends_at','kind','tone','description'?]
 *
 * Times are returned as date-anchored CarbonImmutables (start-of-day /
 * end-of-day) since these are 24-hour-grain events, not specific
 * server times.
 */
class WorldEventsCalendar
{
    /**
     * @return list<array{name:string, starts_at:CarbonImmutable, ends_at:CarbonImmutable, kind:string, tone:string, description:?string}>
     */
    public function eventsInRange(CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($from->greaterThan($to)) {
            return [];
        }

        $events = [];
        $cursor = $from->startOfMonth();
        // Loop through every month that overlaps the window. Each month
        // contributes at most one Darkmoon Faire instance.
        while ($cursor->lessThanOrEqualTo($to)) {
            $faire = $this->darkmoonFaireFor($cursor);
            if ($faire['ends_at']->greaterThanOrEqualTo($from) && $faire['starts_at']->lessThanOrEqualTo($to)) {
                $events[] = $faire;
            }
            $cursor = $cursor->addMonth()->startOfMonth();
        }

        usort($events, fn (array $a, array $b) => $a['starts_at']->getTimestamp() <=> $b['starts_at']->getTimestamp());
        return $events;
    }

    /**
     * Darkmoon Faire opens the first Sunday of each month and stays
     * open through the following Saturday. If the 1st is a Sunday it
     * opens that day, otherwise it skips forward to the next Sunday.
     *
     * @return array{name:string, starts_at:CarbonImmutable, ends_at:CarbonImmutable, kind:string, tone:string, description:?string}
     */
    private function darkmoonFaireFor(CarbonImmutable $monthStart): array
    {
        $first = $monthStart->startOfDay();
        // dayOfWeek: 0=Sun .. 6=Sat. Days to add to land on the next Sunday.
        $daysToSunday = (7 - $first->dayOfWeek) % 7;
        $sunday = $first->addDays($daysToSunday)->startOfDay();
        $saturday = $sunday->addDays(6)->endOfDay();

        return [
            'name' => 'Darkmoon Faire',
            'starts_at' => $sunday,
            'ends_at' => $saturday,
            'kind' => 'world',
            'tone' => 'violet',
            'description' => 'Monthly carnival on Darkmoon Island. Profession quests, daily games, and a vendor of unique pets and toys.',
        ];
    }
}
