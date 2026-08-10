# Multi-day event spans on the Social calendar grid

## Why
A 17-day Brewfest renders as the name repeated in 17 separate cells. The month grid is the view the
Social hub added, and long world events are most of what it shows outside raid nights, so the
commonest content is also the worst rendered.

## Not this card
The list view, which reads correctly already. The events themselves and how they are computed.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a multi-day event is rendered in the month grid, THE APP SHALL draw one continuous bar
      across the days it covers, with the name shown once.
- [ ] #2 WHERE an event crosses a week boundary, THE APP SHALL continue the bar on the next row
      rather than dropping or duplicating it.
- [ ] #3 WHEN several multi-day events overlap, THE APP SHALL stack the bars without either
      overlapping the other or changing the height of the week's row unpredictably.
<!-- AC:END -->

## Tasks
- [ ] Render spans with CSS grid `grid-row` / `grid-column` spans rather than per-cell entries
- [ ] Handle the week-boundary split
- [ ] Check against Brewfest and Winter Veil, which are the two longest
