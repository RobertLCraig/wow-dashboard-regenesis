# Multi-day event spans on the Social calendar grid

## Why
A 17-day Brewfest renders as the name repeated in 17 separate cells. The month grid is the view the
Social hub added, and long world events are most of what it shows outside raid nights, so the
commonest content is also the worst rendered.

## Not this card
The list view, which reads correctly already. The events themselves and how they are computed.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a multi-day event is rendered in the month grid, THE APP SHALL draw one continuous bar
      across the days it covers, with the name shown once.
- [x] #2 WHERE an event crosses a week boundary, THE APP SHALL continue the bar on the next row
      rather than dropping or duplicating it.
- [x] #3 WHEN several multi-day events overlap, THE APP SHALL stack the bars without either
      overlapping the other or changing the height of the week's row unpredictably.
<!-- AC:END -->

## Tasks
- [x] Render spans with CSS grid `grid-row` / `grid-column` spans rather than per-cell entries
- [x] Handle the week-boundary split
- [x] Check against Brewfest and Winter Veil, which are the two longest

## Comments

**2026-08-29** The month grid no longer builds a flat list of days each holding its own copy of every
overlapping event. `SocialController::gridWeeks()` now returns one entry per calendar week, and for
each week it clips every overlapping event to that week's Monday..Sunday and emits a single bar with
a start column, a span, and flags saying whether it runs off either end. The Blade view draws each
week as its own CSS grid: row 1 holds the day numbers, one row per lane after that, and the day cells
are a backdrop spanning every row so a bar laid over them stays unbroken across the days it covers.
Bars carry inline `grid-column` / `grid-row` rather than Tailwind classes, because the values are
computed per event and Tailwind cannot see them at build time.

Bars are packed longest-first into three lanes, so two events that overlap in time land on different
rows and a week's height is a function of how many lanes are occupied - not of which cell you look
at. Three lanes is the same visible ceiling the old per-cell view had; anything that will not fit is
counted per day and shown as "+n more" rather than dropped. Brewfest (17 days) and Winter Veil (18,
across the year end) both come out as three bars each with the lanes free, which is the check the
card asked for and is covered by a test.

Two readings I settled myself, both worth a look in review. First, "the name shown once" - I read
that as once per bar rather than once across the whole grid, so a week-2 continuation still carries
the name, prefixed with an arrow. A nameless continuation bar tells a reader nothing, and this is
what Google Calendar and Outlook both do. Second, an event with no `ends_at` is treated as a
single-day bar, which is what the old per-cell code did too.

Not verified in a browser. Herd serves this project from `C:\Dev\Regenesis`, not from the worktree
the card was built in, so the grid still needs one look on a real page - particularly the 1px gaps
where a bar meets a week edge, and how the three-lane rows look on a narrow window.

`.\vendor\bin\pint.bat` reformats 183 files in this repository, none of them mine - the tree has
never been Pint-clean. I reverted all of that and kept only my three files, which Pint passes on
their own. Making the repository Pint-clean is a real job but it is not this card, and it would have
buried the diff.
