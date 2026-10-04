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
- [ ] #1 WHEN a GRM import moves a member from a trial team to a raid team by their rank, THE APP SHALL write one `team_left` event for the old team and one `team_joined` event for the new team, each naming the team in its payload. proves: `it records a team change made by a rank change`
- [ ] #2 WHEN an officer sets or clears a team override on the character page, THE APP SHALL write `team_joined` / `team_left` events for exactly the teams that changed, with the officer's user id in the payload. proves: `it records a team change made by an officer override`
- [ ] #3 WHEN a recompute leaves a member's teams exactly as they were, THE APP SHALL write no event. proves: `it writes no team event when nothing changed`
- [ ] #4 WHEN a recompute from `/admin/teams` changes the teams of many members, THE APP SHALL write one event per team gained or lost per member, and no more. proves: `it records team changes from a mapping recompute`
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
