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
- [x] #1 WHEN a Discord user with an ordinary guild-member role signs in, THE APP SHALL grant access
      to Social and Roster.
- [x] #2 WHEN that same user reaches any admin or officer page, THE APP SHALL refuse.
- [x] #3 WHEN a user with no guild role at all signs in, THE APP SHALL refuse everything, as now.
- [x] #4 THE OFFICER experience SHALL be unchanged, proved by walking an officer through the pages
      that were officer-only before.
<!-- AC:END -->

## Tasks
- [x] Add a `member` tier and surface it in `RoleVerifier`
- [x] Replace the blanket `OfficerOnly` with a route-by-route gate
- [x] Walk both roles through every route, because criterion #4 is the one a middleware swap breaks

## Plan
The risk here is the inverse of the bug: a route-by-route gate makes the default open rather than
closed, so an admin page that nobody remembers to gate becomes visible to the whole guild. List the
routes first and gate from that list, rather than gating as each page is noticed.

## Comments
**2026-08-29** Built the `member` tier and the per-route gate.

`OfficerOnly` is now `RequireTier`, which takes a minimum tier and defaults to `officer`. Routes name
the exception rather than the rule: a small group at the top of `routes/web.php` carries
`RequireTier:member` and holds three routes only - `/dashboard/social`, `/roster`, `/roster.csv`.
Everything else stays in the officer group and is refused for a member. That answers the Plan's
worry: the default stayed closed, and a page opens to the guild only by being moved into that short
list on purpose. A misspelt tier name in a route definition locks the route rather than opening it.

`member` sits below `raid_leader` on a single ladder now held in `User::TIER_RANK`; `isOfficerTier()`
still excludes it, so every officer Gate is untouched. Two Gates moved to member-and-above -
`dashboard.social.view` and `roster.view` - which is what makes the sidebar show a member exactly
Social and Roster and no Admin section. The roster's kick / rank / note controls were already behind
`roster.kick`, so they stay hidden and their POST endpoints stay officer-gated.

Sign-in used to land everyone on `/dashboard`, which a member cannot open, so `User::homeRoute()`
sends members to Social and officers to the General dashboard; the landing page and the sidebar logo
follow it.

Tests: `tests/Feature/MemberTierTest.php`, 12 tests. Criterion #4 is a walk read off the router - it
requests every parameter-free officer GET page as an officer and requires 200 or 302, so a route
added later is walked too and a page that breaks fails the walk rather than passing as "not 403".
Full suite 717 passed, Pint clean.

What I could not settle here:

- **The member role's Discord id is not in the repository.** `.env` holds the four officer role ids
  and no member one, and `.env` is your file. `DISCORD_ROLE_MEMBER` is added to `.env.example`, blank,
  and an unset role can never match - so until you paste the real snowflake into `.env`, nobody is a
  member and the dashboard behaves exactly as it did before. That is the one manual step to finish
  this card.
- **It still needs a browser check.** This branch is a worktree; Herd serves the site from
  `C:\Dev\Regenesis`, so nothing here was seen in a browser. Worth eyeballing the sidebar as a member
  once the role id is set.
- **Character pages and the Farm planner stayed officer-only**, because the card grants Social and
  Roster and nothing wider. A member browsing the Roster therefore sees character-name links that 403
  when clicked. Hiding or unlocking them is a call for you, not for this card.
- **Pint reformats about 180 files repo-wide**, so it has clearly not been run over this codebase in
  a while. I reverted all of that and kept only the files this card touches, rather than burying the
  change in a whole-repo reformat.
