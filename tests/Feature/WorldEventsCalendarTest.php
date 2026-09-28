<?php

use App\Services\WorldEvents\WorldEventsCalendar;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;

it('returns the Darkmoon Faire for a month whose 1st is mid-week', function () {
    // April 2026: 1st is Wednesday. First Sunday is the 5th.
    $events = (new WorldEventsCalendar)->eventsInRange(
        CarbonImmutable::parse('2026-04-01'),
        CarbonImmutable::parse('2026-04-30'),
    );
    $faire = collect($events)->firstWhere('name', 'Darkmoon Faire');
    expect($faire)->not->toBeNull();
    expect($faire['starts_at']->toDateString())->toBe('2026-04-05');
    expect($faire['ends_at']->toDateString())->toBe('2026-04-11');
});

it('opens Darkmoon Faire on the 1st when the month starts on a Sunday', function () {
    // March 2026: 1st is Sunday. Faire runs the 1st through the 7th.
    $events = (new WorldEventsCalendar)->eventsInRange(
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-03-31'),
    );
    $faire = collect($events)->firstWhere('name', 'Darkmoon Faire');
    expect($faire['starts_at']->toDateString())->toBe('2026-03-01');
    expect($faire['ends_at']->toDateString())->toBe('2026-03-07');
});

it('returns one Darkmoon Faire per month across a multi-month window', function () {
    $events = (new WorldEventsCalendar)->eventsInRange(
        CarbonImmutable::parse('2026-04-01'),
        CarbonImmutable::parse('2026-06-30'),
    );
    $faires = array_values(array_filter($events, fn ($e) => $e['name'] === 'Darkmoon Faire'));
    expect($faires)->toHaveCount(3);
    expect($faires[0]['starts_at']->toDateString())->toBe('2026-04-05');
    expect($faires[1]['starts_at']->toDateString())->toBe('2026-05-03');
    expect($faires[2]['starts_at']->toDateString())->toBe('2026-06-07');
});

it('skips a Darkmoon Faire that starts before the window opens', function () {
    // Window opens 2026-04-08, mid-Faire. Faire that started 2026-04-05
    // ends 2026-04-11 so it overlaps and is included.
    $events = (new WorldEventsCalendar)->eventsInRange(
        CarbonImmutable::parse('2026-04-08'),
        CarbonImmutable::parse('2026-04-30'),
    );
    $faires = array_values(array_filter($events, fn ($e) => $e['name'] === 'Darkmoon Faire'));
    expect($faires)->toHaveCount(1);
    expect($faires[0]['starts_at']->toDateString())->toBe('2026-04-05');
});

it('skips a Darkmoon Faire that has already ended before the window', function () {
    // Window opens 2026-04-12, the Sunday after the April Faire ends.
    $events = (new WorldEventsCalendar)->eventsInRange(
        CarbonImmutable::parse('2026-04-12'),
        CarbonImmutable::parse('2026-04-30'),
    );
    $faires = array_values(array_filter($events, fn ($e) => $e['name'] === 'Darkmoon Faire'));
    expect($faires)->toBe([]);
});

it('returns an empty list when the from date is after the to date', function () {
    $events = (new WorldEventsCalendar)->eventsInRange(
        CarbonImmutable::parse('2026-05-01'),
        CarbonImmutable::parse('2026-04-01'),
    );
    expect($events)->toBe([]);
});

it('marks the Trading Post reset on the 1st of each month', function () {
    $events = (new WorldEventsCalendar)->eventsInRange(
        CarbonImmutable::parse('2026-04-01'),
        CarbonImmutable::parse('2026-06-30'),
    );
    $tradingPosts = array_values(array_filter($events, fn ($e) => $e['name'] === 'Trading Post reset'));
    expect($tradingPosts)->toHaveCount(3);
    expect($tradingPosts[0]['starts_at']->toDateString())->toBe('2026-04-01');
    expect($tradingPosts[1]['starts_at']->toDateString())->toBe('2026-05-01');
    expect($tradingPosts[2]['starts_at']->toDateString())->toBe('2026-06-01');
});

it('returns Brewfest at the canonical Sept 20 - Oct 6 window', function () {
    $events = (new WorldEventsCalendar)->eventsInRange(
        CarbonImmutable::parse('2026-09-01'),
        CarbonImmutable::parse('2026-10-31'),
    );
    $brewfest = array_values(array_filter($events, fn ($e) => $e['name'] === 'Brewfest'));
    expect($brewfest)->toHaveCount(1);
    expect($brewfest[0]['starts_at']->toDateString())->toBe('2026-09-20');
    expect($brewfest[0]['ends_at']->toDateString())->toBe('2026-10-06');
});

it("returns Hallow's End at the canonical Oct 18 - Nov 1 window", function () {
    $events = (new WorldEventsCalendar)->eventsInRange(
        CarbonImmutable::parse('2026-10-01'),
        CarbonImmutable::parse('2026-11-30'),
    );
    $halloween = array_values(array_filter($events, fn ($e) => $e['name'] === "Hallow's End"));
    expect($halloween)->toHaveCount(1);
    expect($halloween[0]['starts_at']->toDateString())->toBe('2026-10-18');
    expect($halloween[0]['ends_at']->toDateString())->toBe('2026-11-01');
});

it('handles a Feast of Winter Veil that spans the year boundary', function () {
    // Window opens after Winter Veil 2025 already started; the event
    // ends 2026-01-02 so it should still surface.
    $events = (new WorldEventsCalendar)->eventsInRange(
        CarbonImmutable::parse('2025-12-25'),
        CarbonImmutable::parse('2026-01-15'),
    );
    $winterVeil = array_values(array_filter($events, fn ($e) => $e['name'] === 'Feast of Winter Veil'));
    expect($winterVeil)->toHaveCount(1);
    expect($winterVeil[0]['starts_at']->toDateString())->toBe('2025-12-16');
    expect($winterVeil[0]['ends_at']->toDateString())->toBe('2026-01-02');
});

it('does not duplicate annual holidays when the window spans multiple years', function () {
    // Two-year window; expect exactly two of each yearly holiday.
    $events = (new WorldEventsCalendar)->eventsInRange(
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2027-12-31'),
    );
    $brewfests = array_filter($events, fn ($e) => $e['name'] === 'Brewfest');
    $midsummers = array_filter($events, fn ($e) => $e['name'] === 'Midsummer Fire Festival');
    expect($brewfests)->toHaveCount(2);
    expect($midsummers)->toHaveCount(2);
});

it('returns Love is in the Air at Feb 7-21', function () {
    $events = (new WorldEventsCalendar)->eventsInRange(
        CarbonImmutable::parse('2026-02-01'),
        CarbonImmutable::parse('2026-02-28'),
    );
    $love = collect($events)->firstWhere('name', 'Love is in the Air');
    expect($love)->not->toBeNull();
    expect($love['starts_at']->toDateString())->toBe('2026-02-07');
    expect($love['ends_at']->toDateString())->toBe('2026-02-21');
});

it("returns Children's Week at May 1-7", function () {
    $events = (new WorldEventsCalendar)->eventsInRange(
        CarbonImmutable::parse('2026-05-01'),
        CarbonImmutable::parse('2026-05-31'),
    );
    $week = collect($events)->firstWhere('name', "Children's Week");
    expect($week)->not->toBeNull();
    expect($week['starts_at']->toDateString())->toBe('2026-05-01');
    expect($week['ends_at']->toDateString())->toBe('2026-05-07');
});

it('returns Day of the Dead at Nov 1-3', function () {
    $events = (new WorldEventsCalendar)->eventsInRange(
        CarbonImmutable::parse('2026-11-01'),
        CarbonImmutable::parse('2026-11-30'),
    );
    $dotd = collect($events)->firstWhere('name', 'Day of the Dead');
    expect($dotd)->not->toBeNull();
    expect($dotd['starts_at']->toDateString())->toBe('2026-11-01');
    expect($dotd['ends_at']->toDateString())->toBe('2026-11-03');
});

// The three moving holidays: Noblegarden tracks Easter, the Lunar
// Festival tracks Lunar New Year, Pilgrim's Bounty tracks US
// Thanksgiving. Table-driven, so the test is table-driven too.
it('places each moving holiday on the right dates for its year', function (int $year, string $name, string $start, string $end) {
    $events = (new WorldEventsCalendar)->eventsInRange(
        CarbonImmutable::parse($year.'-01-01'),
        CarbonImmutable::parse($year.'-12-31'),
    );
    $found = array_values(array_filter($events, fn ($e) => $e['name'] === $name));
    expect($found)->toHaveCount(1);
    expect($found[0]['starts_at']->toDateString())->toBe($start);
    expect($found[0]['ends_at']->toDateString())->toBe($end);
})->with([
    [2025, 'Noblegarden', '2025-04-21', '2025-04-27'],
    [2026, 'Noblegarden', '2026-04-06', '2026-04-12'],
    [2027, 'Noblegarden', '2027-03-29', '2027-04-04'],
    [2028, 'Noblegarden', '2028-04-17', '2028-04-23'],
    [2029, 'Noblegarden', '2029-04-02', '2029-04-08'],
    [2030, 'Noblegarden', '2030-04-22', '2030-04-28'],
    [2025, 'Lunar Festival', '2025-01-29', '2025-02-12'],
    [2026, 'Lunar Festival', '2026-02-17', '2026-03-03'],
    [2027, 'Lunar Festival', '2027-02-06', '2027-02-20'],
    [2028, 'Lunar Festival', '2028-01-26', '2028-02-09'],
    [2029, 'Lunar Festival', '2029-02-13', '2029-02-27'],
    [2030, 'Lunar Festival', '2030-02-03', '2030-02-17'],
    [2025, "Pilgrim's Bounty", '2025-11-23', '2025-11-29'],
    [2026, "Pilgrim's Bounty", '2026-11-22', '2026-11-28'],
    [2027, "Pilgrim's Bounty", '2027-11-21', '2027-11-27'],
    [2028, "Pilgrim's Bounty", '2028-11-19', '2028-11-25'],
    [2029, "Pilgrim's Bounty", '2029-11-18', '2029-11-24'],
    [2030, "Pilgrim's Bounty", '2030-11-24', '2030-11-30'],
]);

it('omits the moving holidays for a year the lookup does not cover', function () {
    // 2031 has no row in the table. The fixed-date holidays still land,
    // which is what proves the year itself was processed and only the
    // three moving ones dropped out.
    $events = (new WorldEventsCalendar)->eventsInRange(
        CarbonImmutable::parse('2031-01-01'),
        CarbonImmutable::parse('2031-12-31'),
    );
    $names = array_column($events, 'name');

    expect($names)->not->toContain('Noblegarden');
    expect($names)->not->toContain('Lunar Festival');
    expect($names)->not->toContain("Pilgrim's Bounty");
    expect($names)->toContain('Brewfest');
});

it('still renders a moving holiday whose description is missing', function () {
    // A fourth holiday added to the date table alone must not take the
    // feed down; it lands without a blurb, like a fixed-date holiday can.
    $calendar = new class extends WorldEventsCalendar
    {
        protected const MOVING_HOLIDAYS = [2026 => [['Undescribed Fest', '05-10', '05-12']]];
    };
    $events = $calendar->eventsInRange(
        CarbonImmutable::parse('2026-05-01'),
        CarbonImmutable::parse('2026-05-31'),
    );
    $fest = collect($events)->firstWhere('name', 'Undescribed Fest');

    expect($fest)->not->toBeNull();
    expect($fest['starts_at']->toDateString())->toBe('2026-05-10');
    expect($fest['description'])->toBeNull();
});

it('names the same holidays in the date table and the description table', function () {
    $class = new ReflectionClass(WorldEventsCalendar::class);
    $dated = collect($class->getConstant('MOVING_HOLIDAYS'))->flatten(1)->pluck(0)->unique()->sort()->values()->all();
    $described = collect($class->getConstant('MOVING_HOLIDAY_DESCRIPTIONS'))->keys()->sort()->values()->all();

    expect($dated)->toBe($described);
});

// The moving-holiday table is hand-filled and an unlisted year is
// omitted without a word. This guard makes the gap loud while there is
// still time to extend the table, before the year-ahead feed reaches it.
const MOVING_HOLIDAY_TABLE_MIN_YEARS_AHEAD = 2;

function assertMovingHolidayTableRunsAhead(): void
{
    $lastYear = max(array_keys((new ReflectionClass(WorldEventsCalendar::class))->getConstant('MOVING_HOLIDAYS')));
    $thisYear = now()->year;

    if ($lastYear - $thisYear < MOVING_HOLIDAY_TABLE_MIN_YEARS_AHEAD) {
        Assert::fail(
            "WorldEventsCalendar::MOVING_HOLIDAYS ends in {$lastYear}, less than "
            .MOVING_HOLIDAY_TABLE_MIN_YEARS_AHEAD." years past {$thisYear}. Add the next years' rows "
            ."before the year-ahead feed runs past it and Noblegarden, the Lunar Festival and Pilgrim's Bounty vanish."
        );
    }
}

it('keeps the moving holiday table at least two years ahead of today', function () {
    assertMovingHolidayTableRunsAhead();
    expect(true)->toBeTrue(); // the guard itself fails the test; this only marks it as asserting
});

it('fails when the moving holiday table ends within two years', function () {
    $lastYear = max(array_keys((new ReflectionClass(WorldEventsCalendar::class))->getConstant('MOVING_HOLIDAYS')));

    // On the threshold itself the table is still far enough ahead.
    $this->travelTo(CarbonImmutable::create($lastYear - MOVING_HOLIDAY_TABLE_MIN_YEARS_AHEAD, 12, 31));
    expect(fn () => assertMovingHolidayTableRunsAhead())->not->toThrow(AssertionFailedError::class);

    $this->travelTo(CarbonImmutable::create($lastYear - MOVING_HOLIDAY_TABLE_MIN_YEARS_AHEAD + 1, 1, 1));
    expect(fn () => assertMovingHolidayTableRunsAhead())
        ->toThrow(AssertionFailedError::class, 'WorldEventsCalendar::MOVING_HOLIDAYS');
});

it('does not duplicate a moving holiday across a multi-year window', function () {
    $events = (new WorldEventsCalendar)->eventsInRange(
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2028-12-31'),
    );
    foreach (['Noblegarden', 'Lunar Festival', "Pilgrim's Bounty"] as $name) {
        $found = array_filter($events, fn ($e) => $e['name'] === $name);
        expect($found)->toHaveCount(3, $name);
    }
});
