# Year-aware lookup for the moving holidays

## Why
The world-events feed computes holidays from stable absolute dates, which covers most of them.
Three do not have stable dates: Noblegarden follows Easter, the Lunar Festival follows Lunar New
Year, and Pilgrim's Bounty follows US Thanksgiving. They are either absent or wrong, and a calendar
that is wrong about three events is one people stop trusting for the other twenty.

## Links

**Relates to**
- `0006` - draws these same events as bars on the month grid; this card only settles which dates
  they fall on, so a wrong date here shows up as a wrong bar there.

## Not this card
The events feed itself, the ICS export, or the month grid, which is card 0006.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN the feed is generated for any year in its window, NOBLEGARDEN, THE LUNAR FESTIVAL and
      PILGRIM'S BOUNTY SHALL each appear on the correct dates for that year.
- [x] #2 WHERE a year has no entry in the lookup, THE APP SHALL omit those three events rather than
      placing them on a guessed date.
- [x] #3 THE PUBLIC year-ahead ICS feed SHALL carry the same dates as the in-app view.
- [x] #4 WHERE a row in the moving-holiday table has no matching description, THE APP SHALL still
      render the event, and a test SHALL fail if the two tables in `WorldEventsCalendar` stop naming
      the same holidays.
<!-- AC:END -->

## Tasks
- [x] Add a year-keyed table or config map for the three moving holidays
- [x] Populate it for the years the year-ahead window can reach
- [x] Make the missing-year case omit rather than guess

## Plan
Criterion #2 matters more than it looks. Computing Easter is a known algorithm and Lunar New Year
is not, so a code path that derives one and guesses the other is worse than a table that is honest
about running out.

## Comments

**2026-08-29** Added a `MOVING_HOLIDAYS` const to `app/Services/WorldEvents/WorldEventsCalendar.php`,
keyed by year, one row per holiday as `[name, start MM-DD, end MM-DD]`. A private
`movingHolidaysFor(int $year)` reads it and returns `[]` for an unlisted year, and the existing
per-year loop in `eventsInRange()` calls it beside `annualHolidaysFor()`. Everything downstream -
the Social page, the per-user social ICS feed and the public world ICS feed - already goes through
that one method, so criterion #3 needed no code change; there was never a second date source to
drift from. I added a test that pins the clock and asserts the public feed's `DTSTART`/`DTEND`
lines match what the calendar service returns for the same year-ahead window, so a future second
source would fail loudly.

Populated 2025 to 2030. Today's year-ahead window reaches 2027, so that is five years of headroom;
the table's docblock says to extend it before the window runs past the last row. 2031 has no row,
and the omit test asserts the three vanish while Brewfest still lands - which is what proves the
year was processed rather than skipped.

Assumed, and worth a human eye: the dates are derived from the anchor holiday by the rule Blizzard
has used in recent years, not read off an official Blizzard calendar. Noblegarden is the Monday
after Easter Sunday for seven days, the Lunar Festival is Lunar New Year plus fifteen days, and
Pilgrim's Bounty is the Sunday before US Thanksgiving for seven days. Those match the 2025 and 2026
windows as I know them, but Blizzard is not contractually bound to the rule and has shifted a
window before. The repository holds no record of past in-game holiday dates to check against, so I
could not verify the far years from anything here. The existing fixed-date holidays carry the same
day-grain approximation, so this is not a new class of error - but if a year is later found wrong,
correct that row rather than the derivation, because the derivation is not code.

748 tests pass. Pint reformats about 175 pre-existing files repo-wide, so I reverted all of that
and kept the diff to this card's three files. `pint --test` is clean on the service; it still
flags `new_with_parentheses` on the two test files, which is the style every existing line in them
already uses, so my additions match their neighbours rather than dragging an unrelated reformat
into this card.

Built in a worktree, so nothing here has been seen in a browser. `/dashboard/social` still wants
one look after merge to confirm the three render in the list and the month grid.

### 2026-08-29 review (v20260829195123-5398)

**suite**

`vendor\bin\pest.bat` exited 0 after 78s, run by this job rather than reported by the card.

**acceptance: sound**

I tried to break all three criteria. I could not.

**#1 ÔÇö right dates for the year.** `WorldEventsCalendar::movingHolidaysFor()` reads the `MOVING_HOLIDAYS` const in `app/Services/WorldEvents/WorldEventsCalendar.php` and builds real events. `eventsInRange()` calls it once per year in the window, next to `annualHolidaysFor()`. I checked all 18 rows by hand against the rule the card states. Easter Sunday, Lunar New Year and the 4th Thursday of November for 2025ÔÇô2030 all agree with the table, including the Monday-after and Sunday-before offsets and the 15-day Lunar span. No row is wrong.

**#2 ÔÇö omit, do not guess.** `movingHolidaysFor()` ends in `self::MOVING_HOLIDAYS[$year] ?? []`. No fallback, no derivation. 2031 yields nothing.

**#3 ÔÇö feed matches the app.** `IcsController::worldFeed()`, `IcsController::socialSubscription()` and `SocialController::index()` all call the one `eventsInRange()`. `IcsBuilder::addWorldEvent()` renders whatever it gets and drops nothing. I grepped the app tree: the three names appear only in the service, so there is no second date source to drift.

I also checked for double-counting, since `eventsInRange()` starts its year loop at `$from->year - 1`. The overlap filter removes the stale year.

VERDICT: sound

**scope: defect**

Scope, production code: clean. Only `WorldEventsCalendar` grew ÔÇö `MOVING_HOLIDAYS`, `MOVING_HOLIDAY_DESCRIPTIONS`, and `movingHolidaysFor()`, which `eventsInRange()` calls beside `annualHolidaysFor()`. Nothing in the ICS export, the Social page, or the month grid was touched, so the 0006 fence holds. Grepping the three names finds one date source, so #3 truly needed no second edit. The two test files only add tests.

Left half done: `docs/planning/next-session.md`, section "1a. Open follow-ups from the BiS / Social work", bullet "Year-aware holiday lookup", still lists this card as open work. This repo's practice is to strike that line in the same commit as the build: card 0005 and card 0007 both did it (`~~...~~ (built <date>, board card NNNN)` plus a short note). Card 0008 did not. The planning doc now tells the next session to build a thing that is already built.

Done beyond the ask, neither over the fence: three new description blurbs in `MOVING_HOLIDAY_DESCRIPTIONS`, and rows for 2028ÔÇô2030 that the year-ahead window cannot yet reach. Both are cheap and I would leave them.

VERDICT: defect

**breakage: defect**

Three findings, all in the `breakage` lens.

**1. Description desync 500s three endpoints.** `WorldEventsCalendar::movingHolidaysFor` reads `self::MOVING_HOLIDAY_DESCRIPTIONS[$row[0]]` with no fallback. The two constants must agree by hand and nothing checks it. A name typo, or a fourth holiday added to `MOVING_HOLIDAYS`, raises "Undefined array key"; Laravel turns that into an ErrorException, taking down `/dashboard/social`, the per-user social ICS and the public `/calendar/world.ics` together ÔÇö from the exact edit the table's docblock invites. `annualHolidaysFor` cannot fail this way, because `yearly()` carries the description inline. The docblock promises `description:?string`, so the omission is a type mismatch too. No test builds it.

**2. The expiry is silent.** When the window in `IcsController::worldFeed` (`now + 365 days`) reaches 2031, the three events stop appearing: no failing test, no log, no signal. A comment cannot page anyone.

**3. Stale doc.** `docs/planning/next-session.md`, "Open follow-ups", still says the year-aware lookup "needs a year-keyed table". Cards 0005 and 0007 were struck through in that same list the same day; the "Social events hub" bullet's event list is now incomplete too.

VERDICT: defect


**2026-09-05** The reviewer returned this card and its finding is the last review entry at the bottom of ## Direction. The loop moved it from todo/ to human-review/ because it has bounced 1 time between todo and ai-review, all 3 criteria ticked. THE BUILDER COULD NOT ACT ON THAT FINDING. A reviewer never unticks a criterion - it is forbidden from editing acceptance at all - so the card came back with 3 of 3 criteria still ticked, every session found nothing open to do, and the loop promoted it again on the boxes. Untick what the reviewer disproved and move it back to todo/, or say here why the finding is wrong.

**2026-09-28** Manager pass: reopened with new criterion #4 because the 2026-08-29 breakage finding
holds on `main`. `WorldEventsCalendar::movingHolidaysFor()` still reads
`self::MOVING_HOLIDAY_DESCRIPTIONS[$row[0]]` with no fallback. A typo, or a fourth holiday added to
`MOVING_HOLIDAYS` alone, throws "Undefined array key" and takes down `/dashboard/social`, the social
ICS feed and the public `/calendar/world.ics` together. #1 to #3 were traced sound and stay ticked.
Also owed: `docs/planning/next-session.md` still lists the year-aware lookup as open work.

**2026-09-28**
RESULT: done
TESTS: +2 new, all green
TOUCHED: app/Services/WorldEvents/WorldEventsCalendar.php
TOUCHED: tests/Feature/WorldEventsCalendarTest.php
TOUCHED: docs/planning/next-session.md
TOUCHED: docs/board/in-progress/0008-year-aware-holiday-lookup.md
TOUCHED: docs/board/todo/0023-moving-holiday-table-runs-out-silently.md
OUT-OF-SCOPE: 0023

#4, first half: `movingHolidaysFor()` now reads the blurb with `?? null`, so an undescribed row
renders with no description, the same shape an undescribed `yearly()` holiday already has. To let a
test build that state, the two tables went from `private` to `protected` and are read through
`static::`; the test is an anonymous subclass carrying a holiday with no blurb. Watched red first on
"Undefined array key "Undescribed Fest"", then green after the fix.

#4, second half: a test reads both constants by reflection and asserts the set of names in
`MOVING_HOLIDAYS` equals the keys of `MOVING_HOLIDAY_DESCRIPTIONS`. It is green on the real tables,
so to watch it catch something I misspelled one 2026 Noblegarden row, ran it red, and restored the
file before the fix.

Struck the "Year-aware holiday lookup" bullet in `docs/planning/next-session.md` in the style 0005
and 0007 used, since the manager pass named it as owed here. The review's second breakage finding,
that the table expires silently in 2031, is not in this card's acceptance, so it is card 0023.

760 tests pass; `pint --test` passes on both touched PHP files. Built in a worktree: no browser
check was done, and `/dashboard/social` still wants one look after merge.
