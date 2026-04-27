<?php

use App\Services\WorldEvents\WorldEventsCalendar;
use Carbon\CarbonImmutable;

it('returns the Darkmoon Faire for a month whose 1st is mid-week', function () {
    // April 2026: 1st is Wednesday. First Sunday is the 5th.
    $events = (new WorldEventsCalendar())->eventsInRange(
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
    $events = (new WorldEventsCalendar())->eventsInRange(
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-03-31'),
    );
    $faire = collect($events)->firstWhere('name', 'Darkmoon Faire');
    expect($faire['starts_at']->toDateString())->toBe('2026-03-01');
    expect($faire['ends_at']->toDateString())->toBe('2026-03-07');
});

it('returns one Darkmoon Faire per month across a multi-month window', function () {
    $events = (new WorldEventsCalendar())->eventsInRange(
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
    $events = (new WorldEventsCalendar())->eventsInRange(
        CarbonImmutable::parse('2026-04-08'),
        CarbonImmutable::parse('2026-04-30'),
    );
    $faires = array_values(array_filter($events, fn ($e) => $e['name'] === 'Darkmoon Faire'));
    expect($faires)->toHaveCount(1);
    expect($faires[0]['starts_at']->toDateString())->toBe('2026-04-05');
});

it('skips a Darkmoon Faire that has already ended before the window', function () {
    // Window opens 2026-04-12, the Sunday after the April Faire ends.
    $events = (new WorldEventsCalendar())->eventsInRange(
        CarbonImmutable::parse('2026-04-12'),
        CarbonImmutable::parse('2026-04-30'),
    );
    $faires = array_values(array_filter($events, fn ($e) => $e['name'] === 'Darkmoon Faire'));
    expect($faires)->toBe([]);
});

it('returns an empty list when the from date is after the to date', function () {
    $events = (new WorldEventsCalendar())->eventsInRange(
        CarbonImmutable::parse('2026-05-01'),
        CarbonImmutable::parse('2026-04-01'),
    );
    expect($events)->toBe([]);
});

it('marks the Trading Post reset on the 1st of each month', function () {
    $events = (new WorldEventsCalendar())->eventsInRange(
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
    $events = (new WorldEventsCalendar())->eventsInRange(
        CarbonImmutable::parse('2026-09-01'),
        CarbonImmutable::parse('2026-10-31'),
    );
    $brewfest = array_values(array_filter($events, fn ($e) => $e['name'] === 'Brewfest'));
    expect($brewfest)->toHaveCount(1);
    expect($brewfest[0]['starts_at']->toDateString())->toBe('2026-09-20');
    expect($brewfest[0]['ends_at']->toDateString())->toBe('2026-10-06');
});

it("returns Hallow's End at the canonical Oct 18 - Nov 1 window", function () {
    $events = (new WorldEventsCalendar())->eventsInRange(
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
    $events = (new WorldEventsCalendar())->eventsInRange(
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
    $events = (new WorldEventsCalendar())->eventsInRange(
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2027-12-31'),
    );
    $brewfests = array_filter($events, fn ($e) => $e['name'] === 'Brewfest');
    $midsummers = array_filter($events, fn ($e) => $e['name'] === 'Midsummer Fire Festival');
    expect($brewfests)->toHaveCount(2);
    expect($midsummers)->toHaveCount(2);
});
