# Record each raid-team change as a member event, so a trial has a history

## Why
Nobody can answer "who did we trial last tier, and what happened to them". The dashboard knows
who is on a trial team **right now**, from the `member_teams` table. It forgets the moment that
changes. When a trial is promoted to the raid team, or drops off it, the old row is deleted and
nothing records that it was ever there.

That history is the whole reason the trial pipeline page was chosen over a simple roster flag.
Every week it is not recorded is a week of trials that page can never show, because history cannot
be rebuilt later from data that was thrown away.

It came to be this way because teams were built to answer "who raids on Mythic tonight", which only
ever needed the present.

## Links

**Relates to**
- `0011` - decided on a dedicated trial pipeline view, and its history needs these events recorded
  first.

## Not this card
- The pipeline page itself. That is `0034`.
- Backfilling history from before this lands. There is no record to backfill from. Rank changes
  (`promoted` / `demoted` events) exist, but turning them into team history means guessing which
  rank meant which team on which date. Do not attempt it.
- Changing how teams are worked out from ranks, Discord roles or officer overrides.
- Showing the new events on the existing Recent activity widget. If they appear there by
  accident, say so on this card rather than hiding them.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a GRM import moves a member from a trial team to a raid team by their rank, THE APP SHALL write one `team_left` event for the old team and one `team_joined` event for the new team, each naming the team in its payload. proves: `it records a team change made by a rank change`
- [x] #2 WHEN an officer sets or clears a team override on the character page, THE APP SHALL write `team_joined` / `team_left` events for exactly the teams that changed, with the officer's user id in the payload. proves: `it records a team change made by an officer override`
- [x] #3 WHEN a recompute leaves a member's teams exactly as they were, THE APP SHALL write no event. proves: `it writes no team event when nothing changed`
- [x] #4 WHEN a recompute from `/admin/teams` changes the teams of many members, THE APP SHALL write one event per team gained or lost per member, and no more. proves: `it records team changes from a mapping recompute`
<!-- AC:END -->

## Tasks
- [ ] Name `team_joined` and `team_left` in `TeamResolver`'s docblock and in `docs/HANDOVER.md`'s list of event types.
- [ ] Write the events from inside `App\Services\Teams\TeamResolver`, where every team write already happens.
- [ ] Add the four tests named above.

## Plan
Stand in the repository root on the card branch. Run `.\vendor\bin\pest.bat` once first, so you
know the suite is green before you touch it.

1. **Where teams change.** Every write to `member_teams` goes through
   `app/Services/Teams/TeamResolver.php`: `replaceRankRows()` (rank-derived),
   `setOverrides()` and `clearOverrides()` (officer). Check that with a search for `MemberTeam::`
   and `member_teams` under `app/` before you start. If you find a writer outside `TeamResolver`,
   route it through `TeamResolver` rather than adding events in a second place.
2. **The diff.** In each of those methods, read the member's teams before the write, compare with
   the teams after, and write one `member_events` row per team gained (`team_joined`) and per team
   lost (`team_left`). Payload: `{"team": "<team>", "via": "rank" | "override", "user_id": <id or null>}`.
   `occurred_at` is now. `snapshot_id` stays null, the same as the computed events already do.
   Do it inside the same `DB::transaction` as the write.
3. **Event type is a string column, not a database enum.** No migration is needed for the new
   types, and an old migration is not edited. Name the two new types in the class docblock of
   `TeamResolver` and in `docs/HANDOVER.md` under "Canonical data shape", where the type list lives.
4. **Readers that switch on `type`.** Search for `->type` and `'type'` near `MemberEvent` under
   `app/` and `resources/views/`. Find out whether the Recent activity widget
   (`resources/views/dashboard/widgets/log-timeline.blade.php`) or the weekly digest would now
   show these events. If either would, and it looks wrong there, filter them out and say so here.
5. **Tests.** Put them in `tests/Feature/TeamMappingTest.php`, beside the existing resolver
   tests. Copy the setup of the nearest test that builds a `TeamMapping` and a `Member`.

"It worked" is the four named tests passing and `.\vendor\bin\pint.bat --test` exiting 0.

## Comments

**2026-10-05** RESULT: done
TESTS: +4 new, all green (792 passed; pint --test passed)
TOUCHED: app/Services/Teams/TeamResolver.php
app/Models/MemberEvent.php
app/Http/Controllers/Dashboard/CharacterTeamOverrideController.php
tests/Feature/TeamMappingTest.php
docs/HANDOVER.md
OUT-OF-SCOPE: none

Built: a private TeamResolver::recordingChanges() wraps every member_teams write (replaceRankRows, setOverrides, clearOverrides). Inside one DB::transaction it reads the member's teams, runs the write, reads again, and writes one team_joined / team_left member_event per team gained / lost. Payload {team, via: rank|override, user_id}; snapshot_id null; occurred_at now. clearOverrides used to call replaceRankRows from inside its own transaction; it now calls a plain writeRankRows() so one clear records once, not twice. The search for MemberTeam:: under app/ found no writer outside TeamResolver.

One change outside TeamResolver: clearOverrides() had no user id parameter, so criterion 2 (officer's id on a clear) could not be met. It now takes ?int $userId = null, setOverrides passes its id through on an empty selection, and CharacterTeamOverrideController passes auth()->id() on 'clear'. Added TYPE_TEAM_JOINED / TYPE_TEAM_LEFT constants on MemberEvent beside the others.

Assumed: a recompute from /admin/teams records via 'rank' with user_id null. recomputeMembers() takes no user, and the card's payload says 'via rank' for rank-derived rows. If the pipeline page wants to know which officer edited the mapping, that is a new card.

Test-first: #1, #2 and #4 were watched red before the code (no events written, array mismatch). #3 ('writes no team event when nothing changed') was green before the code, because no events existed at all; I then swapped the diff for a naive 'every row before is left, every row after is joined' and watched #3 (and #2) go red, then restored it. #3 drives clearOverrides and setOverrides on members whose teams do not change, which rewrite every row, so it catches that naive version.

Readers: the Recent activity widget (log-timeline.blade.php) reads $timeline type_name values from GRM's own log, not member_events, so the new events do not appear there. The weekly digest and DashboardController filter member_events by explicit type lists, so they ignore the new types. The character page's event list shows every member_event type, so it will now show 'team joined' / 'team left' lines with the payload as compact JSON (no 'note' or 'from/to' key to prettify). That is not the Recent activity widget, so I left it alone; say if it looks wrong.

A browser check is still owed: Herd serves C:\Dev\Regenesis, not this worktree.

### 2026-10-05 review (v20261005003655-1759)

**suite**

`vendor\bin\pest.bat` exited 0 after 102s, run by this job rather than reported by the card.

**acceptance: sound**

I could not break any of the four criteria. Every write to the team table goes through one class, `app/Services/Teams/TeamResolver.php`.

**#1: a rank change during a GRM import.** `GrmNormalizer::apply()` calls `TeamResolver::syncRankRowsForMember()`. That calls `replaceRankRows()`, which uses `recordingChanges()`. `recordingChanges()` reads the teams before and after the write. It writes one `team_left` and one `team_joined` event, and each event names its team. The test does a real import twice, with two different ranks.

**#2: an officer override.** `CharacterTeamOverrideController::update()` passes `auth()->id()` (the officer's user id) on a clear. `setOverrides()` and `clearOverrides()` both use `recordingChanges()` with that id. The events cover only the difference between before and after. The test checks a save, a clear, and a save with nothing ticked.

**#3: no change.** `recordingChanges()` compares the two team lists with `array_diff`. If the lists are the same, it writes nothing. `recomputeMembers()` and `syncRankRowsForMember()` also skip any member whose teams did not change. The test rewrites every row and still gets zero events.

**#4: a recompute from `/admin/teams`.** `recomputeMembers()` calls `replaceRankRows()` once for each member that changed. That gives one event for each team gained or lost. Members with an override are skipped. The test checks for exactly 3 events.

I found no other place in `app/` that writes to the team table.

VERDICT: sound

**scope: sound**

I tried to break this card on scope. I could not.

**What the card changed.** The card's commit is `75c3704`. It changed 5 files:
- `TeamResolver`
- `MemberEvent`
- `CharacterTeamOverrideController`
- `TeamMappingTest`
- `docs/HANDOVER.md`

**The other files in the diff are not from this card.** The webhook and digest changes belong to cards `0030` and `0031`, which are already done and were committed earlier.

**One change was outside `TeamResolver`.** The card asked for officer events to carry the officer's user id. To do that on a clear, `clearOverrides()` needed a `$userId` input, so `CharacterTeamOverrideController::update()` now passes the officer's id on a clear. Criterion #2 needs this. It is not scope creep.

**No writer was missed.** `MemberTeam` is only written inside `TeamResolver`. In `GrmNormalizer`, team syncing is done through the resolver.

**No fence was crossed:**
- No backfill was added.
- How teams are worked out did not change.
- No migration was added.
- The new events do not show on the Recent activity widget. They do show on the character page's event list. The builder said so on the card, as the card asked.

**Nothing is half done.** The four named tests exist. Both docs (the `TeamResolver` docblock and `HANDOVER.md`) name the two new event types. The boxes under `## Tasks` are still unticked. That is only bookkeeping.

VERDICT: sound

**breakage: sound**

I tried to break this card and could not. The verdict is **sound**.

**What I checked:**
- **Every team write goes through one place.** The only code that writes `member_teams` is in `TeamResolver`. There is one rank write (`replaceRankRows()`) and two officer writes (`setOverrides()`, `clearOverrides()`), and all three now go through `recordingChanges()`. I searched `app/` for writes outside this class and found none.
- **The callers are up to date.**
  - `recomputeMembers()` and `syncRankRowsForMember()` both still reach `replaceRankRows()`.
  - `CharacterTeamOverrideController::update()` now passes the officer id on both save and clear.
  - The new `?int $userId = null` parameter is optional, so older calls still work.
- **One clear makes one set of events.** `clearOverrides()` now calls `writeRankRows()` directly. Before, it called `replaceRankRows()`, which would also have recorded the change.
- **Other readers do not show the new events.** `DashboardController`, `WeeklyDigestBuilder` and `GrmSnapshotDiffer` each pick events by a fixed list of types, so they skip `team_joined` and `team_left`.
- **One small side effect, not a fault.** The character page list in `CharacterController` (the query at the line `return MemberEvent::query()`) shows every type, with a row limit. Team events now use some of those rows, so older roster events can drop off that list. The builder said this on the card. It is not the Recent activity widget, so no criterion fails.
- **The comments are still true.** The `TeamResolver` class docblock and the `replaceRankRows()` docblock match the code.

All four criteria still hold. You do not need to do anything for this card.

VERDICT: sound

