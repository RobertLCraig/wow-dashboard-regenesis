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
- [x] #1 WHEN nothing happened in the week (no joins, leaves, parses, anniversaries, newly inactive members or action-queue items), THE APP SHALL include one line saying it was a quiet week, under the roster line. proves: `digest says it was a quiet week when nothing happened`
- [x] #2 WHEN anything in that list did happen, THE APP SHALL leave the quiet-week line out. proves: `digest leaves out the quiet-week line when something happened`
- [x] #3 WHEN the guild has no active members, THE APP SHALL replace the roster line with a warning that the roster sync has no data, and SHALL NOT print "0 active". proves: `digest warns instead of reporting zero active members`
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

## Comments

**2026-10-04** RESULT: done
TESTS: +8 new (3 tests, one with a 6-case dataset), 1 changed, all green (788 passed); pint --test exit 0
TOUCHED: app/Services/Digest/WeeklyDigestBuilder.php
TOUCHED: tests/Feature/WeeklyDigestTest.php
OUT-OF-SCOPE: none

Only renderMarkdown() changed. When roster.active is 0 the roster line is replaced by: "⚠️ **Roster has no data** - the GRM sync may have stopped. Check the dashboard before reading anything below." Otherwise, when joined, left, every action-queue count, best_parses, anniversaries and newly_inactive are all zero/empty, the line directly under the roster line is: "Quiet week: no joins, leaves, parses or anniversaries."

Watched red first: #1 and #3 failed on the old output ("**Roster**: 0 active." / no quiet line). #2 cannot fail before the line exists, so I printed the line unconditionally and watched all six dataset cases fail, then added the condition. I then removed each of the six terms of the condition in turn: each removal turned exactly one case red, so every term is guarded by its own case.

Changed existing test: `builder produces markdown even with an empty guild` asserted `0 active`; it now asserts `Roster has no data`, as the card's Plan says.

Assumption: on a zero-active week the quiet-week line is not printed. #1 places it under the roster line, which that week does not have, and "quiet" next to a sync with no data would be the false comfort this card exists to stop.

Not done: `php artisan digest:weekly --dry-run` could not run from the worktree. The .env points at local MySQL (127.0.0.1:3306), and it refused the connection, so there is no real-data output to paste. A reviewer should run the dry run with MySQL up. No browser check applies (no view changed).

### 2026-10-04 review (v20261004212530-6161)

**suite**

`vendor\bin\pest.bat` exited 0 after 97s, run by this job rather than reported by the card.

**acceptance: sound**

I checked each rule against the code. All three hold.

**#1: quiet week.** This is in `WeeklyDigestBuilder::renderMarkdown()`. The `$quiet` check needs all six things to be empty: joined, left, the action-queue sum, best_parses, anniversaries and newly_inactive. When they are, it adds the "Quiet week" line straight after the roster line. The test `digest says it was a quiet week when nothing happened` checks the next line after `**Roster**:`.

**#2: no quiet line when something happened.** The same `$quiet` check handles this. Any one event makes it false. The 6-case test gives each event its own case.

**#3: zero active.** In the same function, `$r['active'] === 0` swaps the roster line for the ⚠️ warning, so "0 active" is never printed. The source file holds a real ⚠️. The strange characters in the diff were a display problem only. The test `digest warns instead of reporting zero active members` adds a member who has left. It then checks that the warning is there and that `0 active` is not.

The builder chose to leave the quiet line out on a zero-active week. That fits the card, because rule #1 says the line goes under the roster line, and that week has no roster line.

The dry run on real data was not done. The card asks for it in the Plan, but no acceptance rule needs it.

VERDICT: sound

**scope: sound**

I checked what this card changed against what it asked for. I found no scope problem.

**The card stayed in its fence.**
- The build commit is `dc80e1e`. It changed 2 files only: `app/Services/Digest/WeeklyDigestBuilder.php` and `tests/Feature/WeeklyDigestTest.php`.
- In the builder, only `renderMarkdown()` changed. `build()` did not change, and the Plan asked for that.
- There is no skip on a quiet week. The schedule did not change. There are no new staleness checks.
- The other files in the diff are from other work: the webhook rule and help text are `0030`, and `0010`, `0011`, `0033`, `0034` and `0035` are separate board moves. This card did not grow into them.

**Nothing is half done.**
- The three named tests exist, and each one tests its criterion.
- The old test `builder produces markdown even with an empty guild` now expects `Roster has no data`. The card comment says so, as the Plan asked.
- There is no quiet-week line when the active count is 0. This is a fair reading of #1, and the comment records it.
- The `digest:weekly --dry-run` paste is still owed, because MySQL was down. It is a step in the Plan, not an acceptance criterion. A person must still run it.
- The boxes under `## Tasks` are still unticked. That is only bookkeeping.

I did not disprove any criterion.

VERDICT: sound

**breakage: sound**

I tried to break this card, and I could not.

**What I checked:**
- `WeeklyDigestBuilder::renderMarkdown()` is called from one place only, `WeeklyDigestBuilder::build()`. No other code turns the roster data into text, so no other caller still prints "0 active".
- The odd characters in the diff (`ÔÜá´©Å`) come from how the diff was displayed. They are not in the file. The file holds a real ⚠️, the same character as the database warning line in the same function.
- The quiet-week check covers all six things the card lists: joins, leaves, every action-queue count, parses, anniversaries and newly inactive members. The things the card says to ignore (progression, M+ scores, the database line) are left out. That matches the Plan.
- `$aq` is still set before the action-queue line uses it. It was only moved up.
- When the active count is zero, the quiet line is not printed. The builder wrote this choice down, and it agrees with #1, which puts the quiet line under the roster line.
- No comment or docblock says "0 active". The new comments are true.
- The changed old test now checks for the warning, as the Plan asked.

**Not a defect:** nobody ran the dry run on real data. The builder said so on the card.

VERDICT: sound

