# Year-aware lookup for the moving holidays

## Why
The world-events feed computes holidays from stable absolute dates, which covers most of them.
Three do not have stable dates: Noblegarden follows Easter, the Lunar Festival follows Lunar New
Year, and Pilgrim's Bounty follows US Thanksgiving. They are either absent or wrong, and a calendar
that is wrong about three events is one people stop trusting for the other twenty.

## Not this card
The events feed itself, the ICS export, or the month grid, which is card 0006.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN the feed is generated for any year in its window, NOBLEGARDEN, THE LUNAR FESTIVAL and
      PILGRIM'S BOUNTY SHALL each appear on the correct dates for that year.
- [ ] #2 WHERE a year has no entry in the lookup, THE APP SHALL omit those three events rather than
      placing them on a guessed date.
- [ ] #3 THE PUBLIC year-ahead ICS feed SHALL carry the same dates as the in-app view.
<!-- AC:END -->

## Tasks
- [ ] Add a year-keyed table or config map for the three moving holidays
- [ ] Populate it for the years the year-ahead window can reach
- [ ] Make the missing-year case omit rather than guess

## Plan
Criterion #2 matters more than it looks. Computing Easter is a known algorithm and Lunar New Year
is not, so a code path that derives one and guesses the other is worse than a table that is honest
about running out.
