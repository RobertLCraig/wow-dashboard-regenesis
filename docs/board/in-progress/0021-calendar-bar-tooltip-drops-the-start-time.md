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
- [ ] WHEN a multi-day event is shown as a bar in the month grid, THE APP SHALL include its start time in the bar's tooltip. proves: `it shows the start time in the tooltip of a multi-day bar`
<!-- AC:END -->

## Tasks
- [ ] Add the start time to the multi-day branch of `title` in `SocialController::gridWeeks()` (`app/Http/Controllers/Dashboard/SocialController.php`)

## Comments
