# Calendar bar tooltip drops the start time of a multi-day event

## Why
On the Social calendar grid, hovering a bar for an event that runs past midnight shows only the days,
for example "Raid Night - Fri 5 Mar to Sat 6 Mar". The start time is shown nowhere in the grid, so a
raid that starts at 20:00 and ends at 01:00 cannot be timed from the calendar. The old per-day chip
always showed the start time; card 0006 rewrote the tooltip and lost it.

## Links

**Relates to**
- `0006` - the multi-day bars rewrite that introduced this; its 2026-08-29 review found it.

## Not this card
The bar layout, the lanes, the list view.

## Acceptance
<!-- AC:BEGIN -->
- [x] WHEN a multi-day event is shown as a bar in the month grid, THE APP SHALL include its start time in the bar's tooltip. proves: `it shows the start time in the tooltip of a multi-day bar`
<!-- AC:END -->

## Tasks
- [x] Add the start time to the multi-day branch of `title` in `SocialController::gridWeeks()` (`app/Http/Controllers/Dashboard/SocialController.php`)

## Comments

**2026-09-29**
RESULT: done
TESTS: +1 new, all green
TOUCHED: app/Http/Controllers/Dashboard/SocialController.php
TOUCHED: tests/Feature/SocialPageTest.php
TOUCHED: docs/board/in-progress/0021-calendar-bar-tooltip-drops-the-start-time.md
OUT-OF-SCOPE: none

The multi-day tooltip now reads "Raid - Fri 19 Mar 20:00 to Sat 20 Mar", the same `D j M H:i` the
single-day branch already used. The new test was watched red first: the bar rendered with
`title="Past Midnight Raid - Fri 19 Mar to Sat 20 Mar"`, so it failed on the missing time and not on a
missing bar. Full suite 762 passed; `pint --test` passed.

Assumed: the end keeps its date only, as the card asks for the start time and nothing more. World
events that start at midnight now show "00:00" in their bar tooltip, as a single-day one already did.
A browser check is still owed: Herd serves Rob's tree, not this worktree.
