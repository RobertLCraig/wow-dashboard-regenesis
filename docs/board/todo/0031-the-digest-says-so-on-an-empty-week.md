# The weekly digest says so on an empty week, and never reports a broken sync as zero

## Why
The digest is about to post to officers every Sunday with nobody pressing the button. Two weeks
will come out wrong today, and nobody will be watching when they do.

1. **A quiet week.** Nobody joined or left, nobody parsed, no anniversaries, nobody went inactive,
   the action queue is empty. The digest still posts, but it is one roster line and nothing else.
   An officer cannot tell "nothing happened" from "the digest lost its sections".
2. **A broken roster sync.** If the GRM sync from the WoW PC stops, or a pull empties the
   members table, the digest posts "Roster: 0 active". That reads as the guild having emptied,
   and it is posted as fact.

`0010` named this as the cost of scheduling the digest: it "will eventually post something wrong
or empty, in public, with no one watching", and the empty case has to be handled deliberately.
Nothing handles it now, because until now an officer read the output before anybody else did.

The builder already applies this rule once. `WeeklyDigestBuilder::database()` treats "no tables"
as a failed probe rather than an empty database, because "printing 0.0 MB would be false
comfort". The roster count has the same problem and no such guard.

## Links

**Relates to**
- `0010` - decided the digest posts on a schedule to the officer channel, and named the empty
  week as the case that must be handled first.

## Not this card
- Where the digest goes, threads, or webhook validation. That is `0030`.
- Skipping the post on a quiet week. A post that says "quiet week" proves the job ran; silence
  cannot be told from a failure. Do not add a skip.
- Staleness checks on every source (Raider.IO, Warcraft Logs, Blizzard). Only the roster count is
  in scope. If you find another section that prints a false zero, raise a card for it.
- The schedule or cadence in `routes/console.php` and `config/digest.php`. They already run the
  digest every Sunday at 09:00 UK and do not change.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN nothing happened in the week (no joins, leaves, parses, anniversaries, newly inactive members or action-queue items), THE APP SHALL include one line saying it was a quiet week, under the roster line. proves: `digest says it was a quiet week when nothing happened`
- [ ] #2 WHEN anything in that list did happen, THE APP SHALL leave the quiet-week line out. proves: `digest leaves out the quiet-week line when something happened`
- [ ] #3 WHEN the guild has no active members, THE APP SHALL replace the roster line with a warning that the roster sync has no data, and SHALL NOT print "0 active". proves: `digest warns instead of reporting zero active members`
<!-- AC:END -->

## Tasks
- [ ] Add the quiet-week line to `WeeklyDigestBuilder::renderMarkdown()`.
- [ ] Replace the "0 active" roster line with a sync warning when the active count is 0.
- [ ] Update the existing test `builder produces markdown even with an empty guild`, which asserts `0 active` today.
- [ ] Add the three tests named above.

## Plan
Stand in the repository root on the card branch. Run `.\vendor\bin\pest.bat` once first, so you
know the suite is green before you touch it.

All the work is in `app/Services/Digest/WeeklyDigestBuilder.php`. `build()` collects a `$data`
array, and `renderMarkdown()` turns it into lines. Change only `renderMarkdown()` if you can.

1. **Quiet week.** "Nothing happened" is all of these at once: `roster.joined` and `roster.left`
   are 0, `action_queue` is all 0, and `best_parses`, `anniversaries` and `newly_inactive` are
   empty. Team progression, top M+ scores and the database line are standing state, not events,
   so they do not count. Keep printing them. Suggested wording: "Quiet week: no joins, leaves,
   parses or anniversaries." Any plain wording is fine.
2. **Zero active.** When `roster.active` is 0, print a warning in place of the roster line, in the
   style of the existing database warning (the line that starts with ⚠️). Suggested wording:
   "⚠️ **Roster has no data** - the GRM sync may have stopped. Check the dashboard before reading
   anything below." A real guild of this size does not reach zero active members, so 0 means the
   data is missing.
3. **Tests.** In `tests/Feature/WeeklyDigestTest.php`. The existing tests build members and
   events with factories and pin time with the builder's `now:` argument. Copy the nearest one.
   The test at the line `it('builder produces markdown even with an empty guild'` expects
   `0 active`. Change it to expect the new warning, and say in your card comment that you did.

"It worked" is the named tests passing, the whole suite green, and
`.\vendor\bin\pint.bat --test` exiting 0. Run `php artisan digest:weekly --dry-run` against your
local data and paste the output into the card comment, so a reviewer sees the real text.
