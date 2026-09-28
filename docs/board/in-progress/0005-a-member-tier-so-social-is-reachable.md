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
- [x] #5 WHEN a member views Social or the Roster, EVERY link, button and form on the page, and in the
      sidebar around it, SHALL lead somewhere a member may open, and nothing a member cannot open
      SHALL be shown to them.
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

### 2026-08-29 review (v20260829170604-8ccb)

**suite**

`vendor\bin\pest.bat` exited 0 after 59s, run by this job rather than reported by the card.

**acceptance: defect**

**#1 traces.** `routes/web.php` member group + `RequireTier::handle()` + `RoleVerifier::tierFromRoles()`. A member reaches `/dashboard/social`, `/roster`, `/roster.csv`.

**#2 traces.** Same `handle()`. Member rank 1 is below officer rank 3, so every other route 403s.

**#3 traces.** `User::rankOf()` returns 0 for null, so null is refused everywhere. `DiscordController::callback()` logs a roleless user out.

**#4 is broken.** Before, `OfficerOnly::handle()` let in anyone with a non-null tier, so a **Raid Leader** reached every officer page. Now `RequireTier::handle()` defaults to `officer`, and `User::TIER_RANK` puts `raid_leader` at 2 against a required 3. A Raid Leader now 403s on every officer page ÔÇö and gets the message "You need a Raid Leader... role", which they have.

Worse, `User::isOfficerTier()` still counts `raid_leader`, and `AppServiceProvider::registerGates()` builds the sidebar from it. A Raid Leader sees every officer link and every link fails.

The suite misses this: `tests/Feature/MemberTierTest.php` walks only `TIER_OFFICER`.

Fix: default the officer group to `raid_leader`, or drop `raid_leader` from `isOfficerTier()`.

VERDICT: defect

**scope: defect**

Two things a member hits that nobody walked.

**1. The theme and clarity buttons 403 for a member.** `resources/views/layouts/dashboard.blade.php` (sidebar footer, the "View clarity" and "Theme" form loops) renders those POST forms for every signed-in user, with no `@can`. But `preferences.display`, `preferences.theme` and `preferences.dashboard-layout` all sit inside the officer group in `routes/web.php`. A member opens Social, clicks "Phoenix", gets 403. The walk in `tests/Feature/MemberTierTest.php` only requests GET pages, so it could not see this.

**2. The Social page itself links to an officer page.** `resources/views/dashboard/social.blade.php` (header button row) renders a "Farm planner" link to `farm-planner.index` with no gate. That is a dead link on the one page this card exists for. The comment disclosed dead character links on the Roster; it did not disclose this one.

Worth a look, not a blocker: `RosterController::csv()` exports `discord_user_id`, `discord_username` and `last_online_at` to any member. The page hides officer controls; the CSV hides nothing.

No fence break found: the Gate split, `homeRoute()` and the copy edits all serve the one tier the card asked for.

VERDICT: defect

**breakage: defect**

Three links and one form on a member's own pages return 403.

**Sidebar preference forms.** `resources/views/layouts/dashboard.blade.php` renders the View-clarity and Theme forms for every signed-in user, with no gate. Both POST to `preferences.display` / `preferences.theme`, which sit in the officer group in `routes/web.php`. A member sees the controls; every click 403s. The clarity dial is an accessibility control. `officerGetUris()` in `tests/Feature/MemberTierTest.php` walks only parameter-free GETs, so no POST route is checked at all.

**Every event on Social.** `SocialController::index` sets `event_url` to `route('events.show', ...)`, and `resources/views/dashboard/social.blade.php` prints it as "Details ÔåÆ" with no gate. `events.show` is officer-only. On the member's landing page, most links 403.

**Farm planner.** Same view, header button to `route('farm-planner.index')`, ungated, officer-only. The card disclosed the Roster character links. It did not disclose these two.

**Empty Admin heading.** The "Admin" label in `layouts/dashboard.blade.php` sits outside the `@can` loop, so a member sees a heading with nothing under it. That contradicts the docblock on `AppServiceProvider::registerGates`, which says the sidebar shows a member exactly the pages they can open.

VERDICT: defect


**2026-08-29** The reviewer returned this card and its finding is the last review entry at the bottom of ## Direction. The loop moved it from todo/ to human-review/ because it has bounced 1 time between todo and ai-review, all 4 criteria ticked. THE BUILDER COULD NOT ACT ON THAT FINDING. A reviewer never unticks a criterion - it is forbidden from editing acceptance at all - so the card came back with 4 of 4 criteria still ticked, every session found nothing open to do, and the loop promoted it again on the boxes. Untick what the reviewer disproved and move it back to todo/, or say here why the finding is wrong.

**2026-09-28** Manager pass: reopened #4 and added #5, because the 2026-08-29 review's findings hold
on `main`. #4: `RequireTier::handle()` still defaults to `User::TIER_OFFICER` (rank 3), while
`User::isOfficerTier()` still counts `raid_leader` (rank 2). A Raid Leader, who reached every officer
page before this card, now gets 403 on each of them while the sidebar still lists them all.
`MemberTierTest` only walks `TIER_OFFICER`. #5: a member still meets officer-only targets on their
own pages: the Theme and View-clarity forms in `layouts/dashboard.blade.php`, the "Farm planner" link
and every "Details" link to `events.show` on `dashboard/social.blade.php`, and an empty "Admin"
heading.

**2026-09-28** Fixed #4 and #5.
RESULT: done
TESTS: +5 new, all green
TOUCHED: app/Http/Middleware/RequireTier.php, app/Http/Controllers/Dashboard/SocialController.php, routes/web.php, resources/views/layouts/dashboard.blade.php, resources/views/dashboard/social.blade.php, resources/views/dashboard/roster.blade.php, tests/Feature/MemberTierTest.php, docs/HANDOVER.md, docs/board/in-progress/0005-a-member-tier-so-social-is-reachable.md, docs/board/todo/0020-roster-csv-gives-every-member-discord-ids.md
OUT-OF-SCOPE: 0020

**#4.** `RequireTier` now defaults to `raid_leader`, not `officer`. That is the set the old
`OfficerOnly` let in and the set `isOfficerTier()` and the sidebar Gates still use, so a Raid Leader
reaches every page the sidebar shows them again. Proved by `it walks a raid leader through every page
that was officer-only`, which was red on `/dashboard` (403) before the change. The officer walk still
passes. I took the reviewer's first fix rather than dropping `raid_leader` from `isOfficerTier()`,
because the second would have taken pages away from Raid Leaders, and #4 says unchanged.

**#5.** `it shows a member nothing on Social that they cannot open` and `it shows a member nothing on
the Roster that they cannot open` render each page as a member (Social in list and grid view, with a
Raid-Helper event; Roster flat and grouped, with a main and an alt), collect every in-app `<a>` and
`<form>`, request every GET link for real, and check every POST form against its route's
`RequireTier`. Both were red first: Social on the Farm planner link, Roster on the character links.
I also moved the theme route back to the officer group for one run and watched the Social test fail
on the theme form, because the Farm planner failure had hidden the forms on the first red run.
`it shows a member no empty Admin heading in the sidebar` was red on the heading.

The fixes:
- The theme and clarity routes moved into the member group. They only write the user's own row.
  The dashboard-layout route stayed officer-only, because only officers see that dashboard.
- Farm planner link and event "Details" links only render for `isOfficerTier()`.
- Roster character names render as plain text for a member, not as links to officer-only pages.
  The 2026-08-29 entry left hiding or unlocking to Rob; #5 now says hide, so I hid them.
- The Admin heading renders only when the user can open at least one Admin link (`@canany`).
- `it still shows an officer the Admin heading and the officer-only links` guards the other side.

What the test cannot see: links built in JavaScript (Alpine `:href`) and off-site links. There are
no in-app Alpine links on these two pages today.

Still owed: a browser check as a member and as a Raid Leader. This is a worktree, so Herd did not
serve these changes. The member role id is still not in `.env`, as the first entry says.
Suite: 757 passed. Pint clean on the PHP files touched.
