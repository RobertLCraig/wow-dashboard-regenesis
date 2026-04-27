<?php

use App\Services\WorldEvents\WorldEventsCalendar;
use Carbon\CarbonImmutable;

it('returns the Darkmoon Faire for a month whose 1st is mid-week', function () {
    // April 2026: 1st is Wednesday. First Sunday is the 5th.
    $events = (new WorldEventsCalendar())->eventsInRange(
        CarbonImmutable::parse('2026-04-01'),
        CarbonImmutable::parse('2026-04-30'),
    );
    expect($events)->toHaveCount(1);
    expect($events[0]['name'])->toBe('Darkmoon Faire');
    expect($events[0]['starts_at']->toDateString())->toBe('2026-04-05');
    expect($events[0]['ends_at']->toDateString())->toBe('2026-04-11');
});

it('opens Darkmoon Faire on the 1st when the month starts on a Sunday', function () {
    // March 2026: 1st is Sunday. Faire runs the 1st through the 7th.
    $events = (new WorldEventsCalendar())->eventsInRange(
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-03-31'),
    );
    expect($events[0]['starts_at']->toDateString())->toBe('2026-03-01');
    expect($events[0]['ends_at']->toDateString())->toBe('2026-03-07');
});

it('returns one Darkmoon Faire per month across a multi-month window', function () {
    $events = (new WorldEventsCalendar())->eventsInRange(
        CarbonImmutable::parse('2026-04-01'),
        CarbonImmutable::parse('2026-06-30'),
    );
    expect($events)->toHaveCount(3);
    expect($events[0]['starts_at']->toDateString())->toBe('2026-04-05');
    expect($events[1]['starts_at']->toDateString())->toBe('2026-05-03');
    expect($events[2]['starts_at']->toDateString())->toBe('2026-06-07');
});

it('skips a Darkmoon Faire that starts before the window opens', function () {
    // Window opens 2026-04-08, mid-Faire. Faire that started 2026-04-05
    // ends 2026-04-11 so it overlaps and is included.
    $events = (new WorldEventsCalendar())->eventsInRange(
        CarbonImmutable::parse('2026-04-08'),
        CarbonImmutable::parse('2026-04-30'),
    );
    expect($events)->toHaveCount(1);
    expect($events[0]['starts_at']->toDateString())->toBe('2026-04-05');
});

it('skips a Darkmoon Faire that has already ended before the window', function () {
    // Window opens 2026-04-12, the Sunday after the April Faire ends.
    $events = (new WorldEventsCalendar())->eventsInRange(
        CarbonImmutable::parse('2026-04-12'),
        CarbonImmutable::parse('2026-04-30'),
    );
    expect($events)->toBe([]);
});

it('returns an empty list when the from date is after the to date', function () {
    $events = (new WorldEventsCalendar())->eventsInRange(
        CarbonImmutable::parse('2026-05-01'),
        CarbonImmutable::parse('2026-04-01'),
    );
    expect($events)->toBe([]);
});
