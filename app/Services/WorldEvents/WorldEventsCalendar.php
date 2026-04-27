<?php

namespace App\Services\WorldEvents;

use Carbon\CarbonImmutable;

/**
 * Computes recurring in-game world events that fall inside a date
 * range. All date math, no API: cadences are stable enough that this
 * doesn't need updating between expansions. The yearly fixed-date
 * holidays drift by 1-2 days some years (timezone / reset edges); if
 * Blizzard ever shifts a window we update the absolute date here.
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

        // Monthly events: one pass per month in the window.
        $cursor = $from->startOfMonth();
        while ($cursor->lessThanOrEqualTo($to)) {
            $events[] = $this->darkmoonFaireFor($cursor);
            $events[] = $this->tradingPostResetFor($cursor);
            $cursor = $cursor->addMonth()->startOfMonth();
        }

        // Annual fixed-date holidays: one pass per year touched. We also
        // include the year before $from->year so a Winter Veil that
        // started the previous December and runs into January is still
        // surfaced when the window opens in January.
        for ($year = $from->year - 1; $year <= $to->year; $year++) {
            foreach ($this->annualHolidaysFor($year) as $event) {
                $events[] = $event;
            }
        }

        // Filter to anything that actually overlaps the window.
        $events = array_values(array_filter(
            $events,
            fn (array $e) => $e['ends_at']->greaterThanOrEqualTo($from)
                && $e['starts_at']->lessThanOrEqualTo($to),
        ));

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

    /**
     * Trading Post resets on the 1st of every month: shop refresh + the
     * monthly faction-track resets. Single-day "event" so it shows up
     * as a marker rather than a span.
     *
     * @return array{name:string, starts_at:CarbonImmutable, ends_at:CarbonImmutable, kind:string, tone:string, description:?string}
     */
    private function tradingPostResetFor(CarbonImmutable $monthStart): array
    {
        return [
            'name' => 'Trading Post reset',
            'starts_at' => $monthStart->startOfDay(),
            'ends_at' => $monthStart->endOfDay(),
            'kind' => 'world',
            'tone' => 'amber',
            'description' => "Traveler's vendor stock refresh and the monthly faction track reset.",
        ];
    }

    /**
     * Major in-game holidays whose dates are stable year-to-year. Times
     * are 00:00 UK to 23:59 UK on the listed dates; Blizzard's actual
     * server-reset boundaries are close enough for a calendar feed.
     *
     * @return list<array{name:string, starts_at:CarbonImmutable, ends_at:CarbonImmutable, kind:string, tone:string, description:?string}>
     */
    private function annualHolidaysFor(int $year): array
    {
        return [
            $this->yearly($year, 2, 7, 2, 21, 'Love is in the Air',
                'The Crown Chemical Co. heroic dungeon event and Lovely Charm Bracelet daily.'),
            $this->yearly($year, 5, 1, 5, 7, "Children's Week",
                'Orphan whistles in Stormwind / Orgrimmar / Dalaran; 1 week to grind the orphan achievements.'),
            $this->yearly($year, 6, 21, 7, 4, 'Midsummer Fire Festival',
                'Capital flames and bonfire daily quests across Azeroth.'),
            $this->yearly($year, 9, 20, 10, 6, 'Brewfest',
                'Dark Iron raid event, Coren Direbrew daily, festive kegs in the capitals.'),
            $this->yearly($year, 10, 18, 11, 1, "Hallow's End",
                'Trick-or-treating, the Headless Horseman world boss, and the Sinister Calling questline.'),
            $this->yearly($year, 11, 1, 11, 3, 'Day of the Dead',
                'Three-day Dia de los Muertos event; quick achievement + a unique pet recipe.'),
            $this->yearly($year, 12, 16, 1, 2, 'Feast of Winter Veil',
                'Greatfather Winter, Metzen the Reindeer dailies, and the Stolen Present quest chain.',
                endsNextYear: true),
        ];
    }

    /**
     * @return array{name:string, starts_at:CarbonImmutable, ends_at:CarbonImmutable, kind:string, tone:string, description:?string}
     */
    private function yearly(
        int $year,
        int $startMonth, int $startDay,
        int $endMonth, int $endDay,
        string $name,
        ?string $description = null,
        bool $endsNextYear = false,
    ): array {
        return [
            'name' => $name,
            'starts_at' => CarbonImmutable::create($year, $startMonth, $startDay)->startOfDay(),
            'ends_at' => CarbonImmutable::create($endsNextYear ? $year + 1 : $year, $endMonth, $endDay)->endOfDay(),
            'kind' => 'world',
            'tone' => 'amber',
            'description' => $description,
        ];
    }
}
