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
- [ ] WHEN the last year in `MOVING_HOLIDAYS` is less than two years past the current year, A TEST
      SHALL fail naming the table, so the gap is loud before the feed reaches it. proves: a test that
      pins the clock past the threshold and fails
<!-- AC:END -->

## Tasks
- [ ] Add the test, with the threshold as one named value

## Comments
