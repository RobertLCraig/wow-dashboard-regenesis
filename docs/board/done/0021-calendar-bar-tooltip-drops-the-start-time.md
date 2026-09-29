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

### 2026-09-29 review (v20260929012646-1008)

**suite**

`vendor\bin\pest.bat` exited 0 after 102s, run by this job rather than reported by the card.

**acceptance: sound**

I tried to break the one acceptance criterion. I could not break it.

**#1: the start time shows in the tooltip of a multi-day bar.** This holds.

- **The code:** `SocialController::gridWeeks()` in `app/Http/Controllers/Dashboard/SocialController.php` makes the bar's `title` (the tooltip text). For a multi-day event, it now uses `$event['starts_at']->format('D j M H:i')`. `H:i` is the hour and minute, so the start time is in the text. Before this change, the code used the day only (`$start->format('D j M')`).
- **A bar that is cut at a week edge:** a long event gets one bar per week. Every bar reads `$event['starts_at']`, and that is the event's real start. It does not use the day where the cut bar begins. So each bar shows the real start time.
- **The test:** `it shows the start time in the tooltip of a multi-day bar` is in `tests/Feature/SocialPageTest.php`. It makes a raid from Friday 20:00 to Saturday 01:00. It checks that the page has `title="Past Midnight Raid - Fri 19 Mar 20:00 to Sat 20 Mar"`. The suite ran green before this review started.

**Limit:** nobody has looked at the tooltip in a browser. That does not disprove the criterion.

VERDICT: sound

**scope: sound**

I checked what card 0021 changed. It stayed inside its own card.

- **The code change is one expression.** In `SocialController::gridWeeks()`, only the multi-day part of `title` changed. It now uses `$event['starts_at']->format('D j M H:i')`. That is the one task on the card. The bar layout and the lanes did not change. The list view did not change. Those are the three things behind the fence ("## Not this card").
- **The test change is one test.** `tests/Feature/SocialPageTest.php` has one new test for this card: `it shows the start time in the tooltip of a multi-day bar`. That is the test that `proves:` names.
- **The other files in the diff belong to other cards.** The lane comment above the `usort` in `gridWeeks()` is card 0022. The colours in `social.blade.php` are card 0024. `WorldEventsCalendar` is cards 0008 and 0023. The import change in `RaiderioSnapshotImporter` is the Pint cleanup. The job change and the README and HANDOVER edits are from other cards. None of them is this card's work.
- **Nothing is half done.** The end time shows its date only. The card asks for the start time only, and the builder wrote that down. The builder did not do a browser check and says so. That does not disprove the criterion.

No criterion is disproved.

VERDICT: sound

**breakage: sound**

I tried to break the change and I could not. The multi-day tooltip now shows the start time, and nothing else is broken.

**What I checked**

- **The change.** In `SocialController::gridWeeks()`, the tooltip (`title`) for an event on more than one day now uses `starts_at` with the time. The one-day tooltip already did this.
- **Long events.** An event that runs over into the next week is drawn as one bar per week. `title` reads the event's real `starts_at`, not the start of that week's bar. So every bar shows the true start day and time.
- **Old tests.** No other test in `tests/` expects the old text without the time. Only the new test checks this text.
- **The comment.** The 0022 comment on this code is still true.
- **Other callers.** The view shows `title` as it is. No other code reads it.

**Limit:** Nobody has looked at it in a browser. This does not disprove the criterion.

VERDICT: sound

