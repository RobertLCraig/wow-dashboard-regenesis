---
needs: 0033
---
# A trial pipeline page: who applied, who is on trial, who made it, who left

## Why
An officer who wants to know where each recruit stands has to piece it together by hand. The
roster's Trial filter shows who is on a trial team today. It cannot show who applied and has not
started, how long a trial has been running, or what became of last tier's trials. So the question
gets asked in officer chat instead, and the answer depends on who remembers.

That costs the officers every recruitment round, and it costs most when several people are mid-trial
at once, which is exactly when nobody can keep it in their head.

The data is already in three places that nothing joins up: the new-recruits forum posts, the raid
teams, and the left / kicked status on each member.

## Links

**Blocked by**
- `0033` - records each team change as an event. Without it, this page has no history to show.

**Relates to**
- `0011` - decided on a dedicated pipeline view over a roster flag, and this card is that view.
- `0035` - asks what "bench" means here and who counts as an applicant. Build without bench; the
  answer adds it.

## Not this card
- A **bench** stage. Nothing in the app records bench today. Leave it out until `0035` is answered.
- Moving people between stages from this page. Stages come from data that already exists. An
  officer changes a stage the way they do today: an in-game rank, or the team override on the
  character page.
- Any change to the roster page or its Trial filter chip.
- Showing the page to the `member` tier. It is officer-only, like every page outside the guild-wide
  group in `routes/web.php`.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN an officer opens `/roster/pipeline`, THE APP SHALL show four columns, Applied, Trial, Raider and Alumni, each with its count. proves: `it shows the four pipeline stages with counts`
- [x] #2 WHEN a member is on `mythic_trial` or `heroic_trial`, THE APP SHALL show them under Trial with the number of days since their latest `team_joined` event for that team, or "since before records" when there is none. proves: `it shows how long a trial has been running`
- [x] #3 WHEN a member is on `mythic` or `heroic` and on no trial team, THE APP SHALL show them under Raider. proves: `it puts raid team members under raider`
- [x] #4 WHEN a member has left or been kicked and has any `team_joined` event, THE APP SHALL show them under Alumni with their last team and the date they left. proves: `it lists a departed trial or raider under alumni`
- [x] #5 WHEN a new-recruits form names a character who is not on any team, THE APP SHALL show them under Applied with the date of the form. proves: `it lists a recruit form with no team under applied`
- [x] #6 WHEN an officer opens a person on the page, THE APP SHALL list their `team_joined` and `team_left` events oldest first. proves: `it shows a person's team history`
- [x] #7 WHEN a user below officer tier requests `/roster/pipeline`, THE APP SHALL refuse it the same way it refuses other officer pages. proves: `it refuses the pipeline page to a member`
<!-- AC:END -->

## Tasks
- [ ] Add the route in the officer-only group of `routes/web.php`, and a sidebar link beside Roster.
- [ ] A controller that sorts people into the four stages, and a Blade view for it.
- [ ] A per-person history list, from `member_events` of type `team_joined` / `team_left`.
- [ ] Add the seven tests named above in `tests/Feature/TrialPipelineTest.php`.

## Plan
Stand in the repository root on the card branch. Run `.\vendor\bin\pest.bat` once first. Read
`docs/HANDOVER.md` "Canonical data shape" before anything else; it explains members, teams and
events in one page.

1. **Where each stage comes from.**
   - Trial and Raider: the `member_teams` table, through `Member::teams()` and the
     `onAnyTeam()` scope in `app/Models/Member.php`. Team names are constants on
     `App\Models\TeamMapping`. Only `status = active` members count.
   - Alumni: `members.status` is `left` or `banned` (soft-deleted rows included, so use
     `withTrashed()` if the model uses soft deletes), and the member has at least one
     `team_joined` event. That event exists only from `0033` onwards, so Alumni starts empty and
     fills over time. Say so in one line on the page, under the column heading.
   - Applied: `discord_recruit_forms`, filled by `app/Services/Discord/DiscordRecruitsImporter.php`.
     A form has a `character_name` but no link to a member. Read the importer and the
     `members` Discord link columns, and find out whether any code matches a form to a member. If
     none does, match by `character_name` against `members.name` before the `-Realm` part, and say
     on this card that you did.
2. **Which forms count.** Until `0035` is answered, show every form from the last 60 days whose
   character is not on any team. Put the 60 in one named constant. `0035` may narrow this to
   raid applicants only, using `form_fields.reason_for_joining`. Make that a one-line change.
3. **Copy the roster's look.** `resources/views/dashboard/roster.blade.php` and
   `RosterController` show how this app renders class colours, names linking to the character
   page, and the accessible table component (`docs/accessibility-guide.md`). Reuse those. Do not
   add new JavaScript; Alpine is already loaded if a person's history needs to expand.
4. **The gate.** Put the route in the group after the comment "Officer-only application surface"
   in `routes/web.php`. Copy how `tests/Feature/RosterTest.php` logs in an officer and a member.

"It worked" is the seven named tests passing and `.\vendor\bin\pint.bat --test` exiting 0. A
browser check is still owed after merge, because Herd serves `C:\Dev\Regenesis` and not this
worktree. Say so in your card comment.

## Comments

**2026-10-05** RESULT: done
TESTS: +7 new, all green (suite 799 passed; pint --test exit 0)
TOUCHED: app/Http/Controllers/Dashboard/TrialPipelineController.php
TOUCHED: resources/views/dashboard/pipeline.blade.php
TOUCHED: resources/views/layouts/dashboard.blade.php
TOUCHED: routes/web.php
TOUCHED: tests/Feature/TrialPipelineTest.php
OUT-OF-SCOPE: none

Built /roster/pipeline (route name pipeline.index, not roster.*, so the Roster sidebar item does not also light up on it) in the officer-only group, plus a 'Trial pipeline' sidebar link under Roster gated on dashboard.view. Four columns, each an x-clarity-table with its count. Each person's team history sits in a native <details>, so no new JavaScript.

Test-first: all seven were watched red against a stub page first. #1-#6 failed because the stage columns were missing. #7 failed with 200 instead of 403 while the stub route sat in the member group. It went green when the route moved to the officer group.

Applied matching: no code links a recruit form to a member. Member::DISCORD_LINK_RECRUIT_FORM exists but nothing writes it. So, as the Plan says, a form's character_name is matched against members.name before the '-Realm' part, case-insensitively. A form whose character matches any member with a member_teams row is left out, and so is a form older than TrialPipelineController::APPLIED_WINDOW_DAYS (60). Repeat forms for one character show once, the newest. 0035 can narrow this to raid applicants by adding one where() line in applied().

Assumptions:
- Trial days count from the latest team_joined whose payload team is the member's trial team. Mythic Trial is used first if they are on both trial teams.
- Alumni 'date they left' is the latest left/kicked/banned member_event. Without one the page says 'left on an unknown date'. Nothing on members records a leave date.
- 'Last team' is the team of the member's latest team_joined event.
- The gate is RequireTier with no argument, like every other officer page, so raid_leader and above get in.

Browser check still owed after merge: Herd serves C:\Dev\Regenesis, not this worktree.
