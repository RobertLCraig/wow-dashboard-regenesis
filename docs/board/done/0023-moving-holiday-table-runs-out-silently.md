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

### 2026-09-29 review (v20260929015106-a54e)

**suite**

`vendor\bin\pest.bat` exited 0 after 66s, run by this job rather than reported by the card.

**acceptance: sound**

I checked the one acceptance criterion against the code. It holds.

**#1: a test fails and names the table when `MOVING_HOLIDAYS` ends less than two years ahead.**

- **The check:** `assertMovingHolidayTableRunsAhead()` in `tests/Feature/WorldEventsCalendarTest.php`. It reads the largest year key in `WorldEventsCalendar::MOVING_HOLIDAYS`. It compares that year with `now()->year`. If the gap is less than `MOVING_HOLIDAY_TABLE_MIN_YEARS_AHEAD` (2), it calls `Assert::fail`. The failure message starts with `WorldEventsCalendar::MOVING_HOLIDAYS`, so it names the table.
- **The real-clock test:** `it keeps the moving holiday table at least two years ahead of today` runs the check on the real date. The table ends in 2030, so this test fails from 2029-01-01.
- **The pinned test:** `it fails when the moving holiday table ends within two years` uses `travelTo` to set the date to 1 January of the first year past the limit. It expects the failure, with the table's name in the message. It also sets the date to 31 December of the last safe year and expects no failure, so an off-by-one mistake would show.
- **One named limit:** the threshold is the single constant `MOVING_HOLIDAY_TABLE_MIN_YEARS_AHEAD`.

One small point: the pinned test catches the failure and passes, so it does not go red itself. That still meets the criterion, because the real-clock test goes red on the real date.

I found nothing that disproves the criterion.

VERDICT: sound

**scope: sound**

I found nothing over the fence, and nothing left half done.

- **Only two files changed.** The build commit for card 0023 is `7c5348f`. It changes only `tests/Feature/WorldEventsCalendarTest.php` and this card. No app code changed. So nothing touches "Not this card": the code does not work out dates, and a year that is not in the table is still left out.
- **The rest of the diff is other cards.** That includes `SocialController`, `SyncBlizzardSnapshotJob`, `RaiderioSnapshotImporter`, the README, the HANDOVER and `social.blade.php`. Those changes came from cards 0006, 0013, 0014, 0015, 0017, 0019, 0021, 0022 and 0024. None of them is from this card.
- **The task is done.** The limit is one named value, `MOVING_HOLIDAY_TABLE_MIN_YEARS_AHEAD`. One helper, `assertMovingHolidayTableRunsAhead()`, does the check. Two tests use it:
  - `it fails when the moving holiday table ends within two years` is the test the criterion names.
  - The test that runs on the real clock is a small extra. It is the part that makes the gap loud before the feed reaches it, which is the card's "Why".

No criterion is disproved.

VERDICT: sound

**breakage: sound**

I tried to break the change for card 0023, and I could not.

I read the new code in `tests/Feature/WorldEventsCalendarTest.php` and checked these points:

- **What it touches.** The change only adds tests. No app code changed, so nothing else in the app can break.
- **Name clash.** In Pest, a helper function or constant in a test file is global, so a second copy with the same name would crash the suite. I searched all of `tests/`. `assertMovingHolidayTableRunsAhead()` and `MOVING_HOLIDAY_TABLE_MIN_YEARS_AHEAD` are each declared only once.
- **The rule.** `assertMovingHolidayTableRunsAhead()` reads the last year of `WorldEventsCalendar::MOVING_HOLIDAYS` from the real class. So if someone adds rows, the guard sees them at once. It fails when the table ends less than two years after today, and the error message names `WorldEventsCalendar::MOVING_HOLIDAYS`.
- **Edges.** The test `it fails when the moving holiday table ends within two years` sets the clock to 31 December of the last safe year and expects no failure. Then it sets 1 January of the next year and expects the failure. So a one-off error would show.
- **Timing.** The feed looks one year ahead. The guard fires on 1 January 2029, and the feed reaches 2031 only in 2030. So the guard warns about a year early.
- **Today.** It is 2026 and the table ends in 2030. The live test passes, which matches the green suite.
- **Docs.** The docblock and `docs/planning/next-session.md` still say to extend the table. That is still true.

I found no defect, so I name no failed criterion.

VERDICT: sound

