# A member tier below officer, so Social is reachable by the guild

## Why
The Social events hub was built as a guild-wide calendar, explicitly not a team or cohort view.
`OfficerOnly` middleware gates the entire dashboard on a gm, big6 or officer Discord role, so the
page nobody but officers can reach is the one page intended for everybody.

A feature shipped behind a gate that contradicts its own purpose is not shipped. This is the oldest
of the open follow-ups and it is what makes the Social work worth having done.

## Not this card
Granular per-page permissions, or a role system. The project's stance is flat now, granular later
via Gates, and this adds exactly one tier because there is now a concrete reason for one.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a Discord user with an ordinary guild-member role signs in, THE APP SHALL grant access
      to Social and Roster.
- [ ] #2 WHEN that same user reaches any admin or officer page, THE APP SHALL refuse.
- [ ] #3 WHEN a user with no guild role at all signs in, THE APP SHALL refuse everything, as now.
- [ ] #4 THE OFFICER experience SHALL be unchanged, proved by walking an officer through the pages
      that were officer-only before.
<!-- AC:END -->

## Tasks
- [ ] Add a `member` tier and surface it in `RoleVerifier`
- [ ] Replace the blanket `OfficerOnly` with a route-by-route gate
- [ ] Walk both roles through every route, because criterion #4 is the one a middleware swap breaks

## Plan
The risk here is the inverse of the bug: a route-by-route gate makes the default open rather than
closed, so an admin page that nobody remembers to gate becomes visible to the whole guild. List the
routes first and gate from that list, rather than gating as each page is noticed.
