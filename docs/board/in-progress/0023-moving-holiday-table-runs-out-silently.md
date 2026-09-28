# The moving-holiday table runs out in 2031 and nothing says so

## Why
`WorldEventsCalendar::MOVING_HOLIDAYS` (`app/Services/WorldEvents/WorldEventsCalendar.php`) has rows
for 2025 to 2030. When the year-ahead window in `IcsController::worldFeed()` reaches 2031,
Noblegarden, the Lunar Festival and Pilgrim's Bounty quietly stop appearing. No test fails and
nothing is logged; only a docblock asks somebody to extend the table. Card 0008's 2026-08-29 review
found it.

## Links

**Relates to**
- `0008` - built the table; omitting an unlisted year is its criterion #2 and stays.

## Not this card
Deriving the dates in code, or changing what happens to an unlisted year. The omit stays.

## Acceptance
<!-- AC:BEGIN -->
- [x] WHEN the last year in `MOVING_HOLIDAYS` is less than two years past the current year, A TEST
      SHALL fail naming the table, so the gap is loud before the feed reaches it; the test pins the
      clock past the threshold and fails. proves: `it fails when the moving holiday table ends
      within two years`
<!-- AC:END -->

## Tasks
- [x] Add the test, with the threshold as one named value

## Comments

**2026-09-29**
RESULT: done
TESTS: +2 new, all green
TOUCHED: tests/Feature/WorldEventsCalendarTest.php, docs/board/in-progress/0023-moving-holiday-table-runs-out-silently.md
OUT-OF-SCOPE: none

`assertMovingHolidayTableRunsAhead()` in the test file reads the last year of `MOVING_HOLIDAYS`
by reflection and calls `Assert::fail` with a message naming `WorldEventsCalendar::MOVING_HOLIDAYS`
when that year is less than `MOVING_HOLIDAY_TABLE_MIN_YEARS_AHEAD` (2) past `now()`. Two tests use it:
`it keeps the moving holiday table at least two years ahead of today` runs on the real clock, and is
the one that goes red on 2029-01-01 with today's table. `it fails when the moving holiday table ends
within two years` pins the clock to the first day past the threshold and expects the failure, and
pins 31 December of the threshold year and expects none, so an off-by-one shows. Watched red first:
with an empty guard the pinned test failed with "AssertionFailedError not thrown". No app code
changed; the omit for an unlisted year stays, per "Not this card". Full suite 764 passed, Pint clean.
