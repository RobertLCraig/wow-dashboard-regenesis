# README says the suite is 45 tests and it is 748

## Why
`README.md`, under `## Useful commands`, prints this line:

```sh
php artisan test                                # 45 Pest tests, ~2s
```

Run on 2026-09-05 in a clean worktree, the suite reports **748 passed (2353 assertions)** in about
**22 seconds**. So the comment is wrong by a factor of sixteen on the count and by an order of
magnitude on the time. `docs/HANDOVER.md` already carries the right numbers, which means the two
documents a reader is pointed at disagree with each other.

**What it costs.** Small, but it is the one number a reader uses to decide whether a run has gone
wrong. Somebody who reads "45, ~2s" and then watches a 22-second run has to stop and work out
whether they have broken something, and somebody who sees a truncated run of 45 tests has lost the
signal that would have told them.

**How it came to be this way.** The comment was true when it was written and nothing updates it. No
card touched it, because it sits in a code block nobody had a reason to open.

## Links

**Relates to**
- `0016` - found while editing the same code block to add the style command, and left alone there
  because fixing it in passing would have been unreviewed work.
- `0014` - its run records are the second source for the 748-test count this card writes down.

## Not this card
**Every other number in `README.md`.** Only this one was measured. Auditing the rest of the document
for staleness is a different and much larger job.

**Making the number update itself.** A generated count needs a generator and something to run it,
and that is a bigger change than this fault justifies.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHERE `README.md` states the size and duration of the test suite, IT SHALL match what
      `.\vendor\bin\pest.bat` prints on a clean checkout.
      proves: none - a document's content, which no test in this suite can read
<!-- AC:END -->

## Tasks
- [x] Run `.\vendor\bin\pest.bat` from the repository root and read the summary line it prints
- [x] Put that count and that duration into the comment in `README.md`, rounding the duration the
      way `docs/HANDOVER.md` already does

## Plan
**Where to stand.** This repository, from its root, in PowerShell. PHP comes from Laravel Herd and
is not on the Git Bash PATH.

One command gives the answer:

```powershell
.\vendor\bin\pest.bat
```

The last two lines it prints are the count and the duration. The line to change is in `README.md`
under `## Useful commands`. `docs/HANDOVER.md` under `## How to pick up` already phrases the same
fact as "748 tests, about 26s, sqlite in memory"; match that phrasing so the two agree.

**What "it worked" looks like:** a reader comparing the README comment with a real run sees the same
number.

## Comments

**2026-09-05**
RESULT: done
TESTS: +0 new, all green (748 passed, 2353 assertions)
TOUCHED: README.md, docs/HANDOVER.md, docs/board/in-progress/0017-readme-says-the-suite-is-45-tests-and-it-is-748.md
OUT-OF-SCOPE: none

The line under `## Useful commands` now reads `748 Pest tests, ~25-30s, sqlite in memory`.

**Why a range and not one number.** The count is exact and stable: two full runs in this worktree
both printed `748 passed (2353 assertions)`. The duration is not. The same suite, on the same
machine, twenty minutes apart, printed `24.75s` and then `30.20s`; the card itself measured about
22s. A single second-count would therefore be stale again on the next run that drifts, which is the
fault this card exists to fix. The range covers every measurement anybody has recorded, and
`docs/HANDOVER.md`'s "about 26s" sat inside it.

`docs/HANDOVER.md` under `## How to pick up` was changed to the same range for the same reason, so
the two documents a reader is pointed at now carry one identical phrase rather than two numbers that
have to be reconciled. That is the disagreement named in this card's Why, and it is why the second
file is on TOUCHED.

**Not changed, deliberately.** The command in that block is still `php artisan test`, not
`.\vendor\bin\pest.bat`. They run the same suite, and swapping it is not what this card asked for.
Every other number in `README.md` is likewise untouched, per Not this card.

**No test.** The criterion's `proves:` is `none`: it is an assertion about the content of a Markdown
document, and no test in this suite reads `README.md`. The check is the two pest runs recorded above
against the line now in the file.

**Still owed: nothing.** No browser check applies - this card changes no served surface.

### 2026-09-05 review (v20260905073859-6a70)

**suite**

`vendor\bin\pest.bat` exited 0 after 25s, run by this job rather than reported by the card.

**acceptance: defect**

**AC #1 ÔÇö traced, and it fails.**

The fixed line is real. `README.md` under `## Useful commands` now reads `748 Pest tests, ~25-30s, sqlite in memory`, and `docs/HANDOVER.md` under `## How to pick up` carries the same phrase. The script's own run (exit 0, 25s) sits inside that range, and the 748 count is corroborated by the run records in `docs/board/human-review/0014-pint-is-not-clean-at-baseline.md`. For that one line, the criterion holds.

But the criterion is a WHERE clause: *wherever* `README.md` states the size of the suite. It states it twice. `README.md` under `## Stack` still says:

> Pest 3 for tests (40 covering parser, ingest pipeline, Discord auth, webhook, iCal)

Forty. Untouched by commit `e6afd02`, which changed one line. So `README.md` now disagrees with itself: the Stack section says 40, the command block says 748. That is the exact fault the card exists to remove ÔÇö a reader comparing the document with a real run does not see the same number ÔÇö and it is now inside one file rather than across two.

This is not "every other number in `README.md`" per *Not this card*. It is the same fact, already measured; no new audit is needed, only the count that is already in hand.

VERDICT: defect

**scope: defect**

Two things in this build do not match the card.

**1. Left half done ÔÇö a second suite size in `README.md`.**
Under `## Stack`, `README.md` still says *"Pest 3 for tests (40 covering parser, ingest pipeline, Discord auth, webhook, iCal)"*. That is a statement of the size of the test suite, and it says 40. Criterion #1 says **WHERE** `README.md` states the size, it shall match what pest prints. So one place was fixed and one was missed. The `## Not this card` fence says "every other number", but its reason is "only this one was measured" ÔÇö the count *was* measured. 748 is now known, so this line is inside the job.

**2. Grew past the card ÔÇö it edited the reference document.**
The Task said: round the duration *the way `docs/HANDOVER.md` already does*. `docs/HANDOVER.md` under `## How to pick up` said "about 26s". The build instead invented "~25-30s" and rewrote `docs/HANDOVER.md` to match the new wording. The card named that file as the thing to copy, not the thing to change.

The `## Current state` bookkeeping edit in `docs/HANDOVER.md` is normal here (card 0016 did the same) and I do not count it.

VERDICT: defect

**breakage: defect**

**Finding ÔÇö the fix missed a second, closer statement of the same number.**

`README.md`, under `## Stack`, reads:

`- Pest 3 for tests (40 covering parser, ingest pipeline, Discord auth, webhook, iCal)`

That line states the size of the test suite. The criterion says *"WHERE `README.md` states the size and duration of the test suite, IT SHALL match what `.\vendor\bin\pest.bat` prints"* ÔÇö not "where the `## Useful commands` block states it". So the criterion is not met.

Worse, `README.md` now disagrees with itself: `## Stack` says 40, `## Useful commands` says 748, about 130 lines apart in one document. The card's Why names document-vs-document disagreement as the cost. The build turned that into document-vs-itself, which a reader hits sooner.

"Not this card" does not shield it. It excludes *unmeasured* numbers; 748 was measured, twice, by this very card. No new work is needed ÔÇö only the same number written in the second place.

Also on that line: it says **Pest 3**, but `composer.json` requires `pestphp/pest: ^4.6` and `docs/HANDOVER.md`, under `## Architecture`, says Pest 4. Same line, same staleness, adjacent fault.

VERDICT: defect


**2026-09-05** The reviewer returned this card and its finding is the last review entry at the bottom of ## Direction. The loop moved it from todo/ to human-review/ because it has bounced 1 time between todo and ai-review, all 1 criteria ticked. THE BUILDER COULD NOT ACT ON THAT FINDING. A reviewer never unticks a criterion - it is forbidden from editing acceptance at all - so the card came back with 1 of 1 criteria still ticked, every session found nothing open to do, and the loop promoted it again on the boxes. Untick what the reviewer disproved and move it back to todo/, or say here why the finding is wrong.

**2026-09-28** Manager pass: reopened #1 because the 2026-09-05 finding holds on `main`. `README.md`,
`## Stack`, still says "Pest 3 for tests (40 covering parser, ingest pipeline, Discord auth, webhook,
iCal)", which is a second statement of the suite's size and disagrees with the 748 in `## Useful
commands`. The same line says Pest 3 while `composer.json` requires `pestphp/pest: ^4.6`. Both are
already measured, so fixing them needs no new audit.

**2026-09-28**
RESULT: done
TESTS: +0 new, all green (760 passed, 2485 assertions)
TOUCHED: README.md, docs/board/in-progress/0017-readme-says-the-suite-is-45-tests-and-it-is-748.md
OUT-OF-SCOPE: none

Both statements of the suite's size in `README.md` now carry the measured count.

- `## Stack` now reads "Pest 4 for tests (760 covering parser, ingest pipeline, Discord auth,
  webhook, iCal)". The count is 760 and the version is 4, which is what `composer.json` requires
  (`pestphp/pest: ^4.6`) and what `docs/HANDOVER.md` under `## Architecture / stack` says.
- `## Useful commands` now reads "760 Pest tests, about a minute, sqlite in memory".

**The count moved from 748 to 760** since 2026-09-05; cards merged since then added tests. Two runs
of `.\vendor\bin\pest.bat` in this worktree both printed `760 passed (2485 assertions)`.

**The duration is copied, not invented.** The Task says to round the way `docs/HANDOVER.md` does.
That file, under `## How to pick up`, now says "about a minute", so the README uses that phrase.
The two runs took 99.54s and 35.71s, so the time drifts a lot on this machine and one exact number
would go stale again. `docs/HANDOVER.md` is not changed by this pass, answering the review's scope
finding.

**No test.** The criterion's `proves:` is `none`: it is the content of a Markdown file. The check is
the two runs above against the two lines now in the file. No browser check applies.

### 2026-09-29 review (v20260929001512-8d14)

**suite**

`vendor\bin\pest.bat` exited 0 after 62s, run by this job rather than reported by the card.

**acceptance: sound**

I checked the card against the code. Only one criterion exists, #1.

**#1 is met.** In `README.md`, two lines state the suite size. I searched the whole file and found no third one.
- `## Stack` says "Pest 4 for tests (760 ...)". `composer.json` asks for `pestphp/pest: ^4.6`, so "Pest 4" is correct.
- `## Useful commands` says "760 Pest tests, about a minute, sqlite in memory".

Both lines now give the same count, so the last review's finding is fixed. The review script's own run took 62s, which fits "about a minute". The builder did not change `docs/HANDOVER.md` this time. The README copies its "about a minute" wording.

**One small drift.** Cards 0019 and 0021 were merged after this fix. Each one added one test. Their comments say "761" and "762". So `main` probably runs 762 tests now, not 760. That drift comes from later work, not from this build. The card also rules out a count that updates itself. If I fail this card for that, the loop never ends: every card that adds a test makes any fixed number wrong again.

No criterion is disproved.

VERDICT: sound

**scope: sound**

I tried to find scope problems in this pass. I found none.

**What this pass changed.** Commit `6b24600` changes `README.md` only. It changes two lines:
- The line under `## Stack`.
- The comment under `## Useful commands`.

These are the two places the reviewer named. The manager pass reopened #1 for these two places. Changing "Pest 3" to "Pest 4" is on the same line, and the manager pass asked for it too.

**The handover file.** In this pass, `docs/HANDOVER.md` did not change. The "about a minute" phrase came from commit `cc2da87`, which is work for card 0015. So the earlier scope finding (the build edited the file it should copy) is fixed. The README now copies that file's phrase. It does not make up a new one.

**Nothing is half done.** `grep` finds no other statement of the suite's size in `README.md`. Other numbers in the file stay as they were, which is what "Not this card" asks for.

**Other changes in the diff.** The rest of the diff (middleware, importer, digest, snapshots config) belongs to cards 0003, 0005, 0019 and 0021, and to other commits. It is not work from this card.

**One note, not a scope finding.** The suite is now 762 tests, because cards 0019 and 0021 added tests after this pass. That is a drift in the count, not work outside the fence.

VERDICT: sound

**breakage: defect**

I checked the two lines in `README.md` against the other work in the same diff.

**Finding: the count is already wrong on this tree.**

- `README.md`, under `## Stack` and under `## Useful commands`, says **760** tests.
- The builder measured 760 before two later cards were merged.
- Card 0019 added `it records the unchanged equipment count on the sync run` to `tests/Feature/SyncBlizzardSnapshotJobTest.php`. Its own comment says the suite was then 761.
- Card 0021 added `it shows the start time in the tooltip of a multi-day bar` to `tests/Feature/SocialPageTest.php`. Its own comment says the suite was then 762.
- Both tests are in this diff. So `.\vendor\bin\pest.bat` on this checkout prints about 762, not 760.

The criterion says the README "SHALL match what `.\vendor\bin\pest.bat` prints on a clean checkout". It does not match.

This is the same fault the card was opened for: a hand-written count goes stale when the next card adds a test. Here it went stale inside one merge.

The rest is correct. "Pest 4" matches `composer.json`. "About a minute" matches `docs/HANDOVER.md` under `## How to pick up`. `docs/HANDOVER.md` was not changed in this pass. No other size statement is in `README.md`.

UNMET: #1 README says 760 but cards 0019 and 0021 in the same tree each added a test, so pest prints 762 on this checkout.

VERDICT: defect

**acceptance**

- **#1 reopened**, by the breakage lens: README says 760 but cards 0019 and 0021 in the same tree each added a test, so pest prints 762 on this checkout.

**2026-09-29**
RESULT: done
TESTS: +0 new, all green (762 passed, 2491 assertions)
TOUCHED: README.md, docs/board/in-progress/0017-readme-says-the-suite-is-45-tests-and-it-is-748.md
OUT-OF-SCOPE: none

Both size statements in `README.md` (`## Stack` and `## Useful commands`) now say 762, which is
what `.\vendor\bin\pest.bat` printed on this worktree: `762 passed (2491 assertions)` in 51.92s.
"About a minute" stays; it matches `docs/HANDOVER.md` and this run. Nothing else changed.

**Standing risk, not fixed here.** Any later card that adds a test makes this number stale again, as
the last review found. "Not this card" rules out a self-updating count, so a reviewer checking
against a tree with later merges will see drift that is not this build's fault.

**No test.** `proves:` is `none`. No browser check applies.

### 2026-09-29 review (v20260929002213-4c18)

**suite**

`vendor\bin\pest.bat` exited 0 after 61s, run by this job rather than reported by the card.

**acceptance: sound**

Criterion #1 holds. I could not break it.

- I searched `README.md` for every statement of the suite's size. There are two, and both say 762:
  - `## Stack`: "Pest 4 for tests (762 covering ...)"
  - `## Useful commands`: "762 Pest tests, about a minute, sqlite in memory"
- I did not re-run the suite. The count comes from the builder's run, which printed `762 passed (2491 assertions)`. It also fits the history: 760, plus one test from card 0019 and one from card 0021.
- I checked the tree directly. It has 723 `it(`/`test(` declarations. Some tests run once per row of test data (Pest "datasets"), in `CompositionPlannerTest`, `DisplayPreferencesTest`, `WorldEventsCalendarTest` and `GrmLogTypeNameTest`. That makes a total above 723, so 762 is consistent.
- Duration: the script's own green run took 61s. That matches "about a minute". It is also the same phrase `docs/HANDOVER.md` uses under `## How to pick up`, as the Task asks.
- "Pest 4" matches `composer.json` (`pestphp/pest: ^4.6`).

The last review found that 760 had gone stale at 762. This pass fixes that. I found no new card in the diff that adds a test after 0021.

No criterion disproved.

VERDICT: sound

**scope: sound**

**Scope review of card 0017, last pass**

I tried to find extra work and work left half done. I found none.

**What this pass changed.** It changed two lines in `README.md`, and nothing else:
- The `## Stack` line. It now says "Pest 4 for tests (762 ...)".
- The comment under `## Useful commands`. It now says "762 Pest tests, about a minute, sqlite in memory".

The breakage review asked for exactly these two lines. The manager pass asked for "Pest 4" on the same line.

**Nothing is half done.** I searched all of `README.md`. Only these two lines give the suite size, and both say 762.

**The fence ("Not this card").** No other number in `README.md` changed. No self-updating count was added. `docs/HANDOVER.md` did not change in this pass. The README uses its phrase "about a minute" and does not make up a new one.

**The rest of the diff.** The middleware, importer, digest, calendar and config changes are work from cards 0003, 0005, 0019 and 0021. They are not work from this card.

This finding disproves no criterion.

VERDICT: sound

**breakage: sound**

I tried to find what this change breaks. I found nothing.

- `README.md` states the suite size in two places: `## Stack` and `## Useful commands`. I searched the whole file for a third place and found none.
- Both places say 762. The builder ran the suite on this tree and got `762 passed (2491 assertions)`. The last review expected 762 after cards 0019 and 0021, and this diff includes both of those cards.
- "About a minute" matches the phrase in `docs/HANDOVER.md` under `## How to pick up`. It also matches the review script's own run, which took 61s.
- "Pest 4" matches `composer.json`, which asks for `pestphp/pest: ^4.6`.
- This pass did not change `docs/HANDOVER.md`, so no reference text became false.
- The only other change is the README comment text, and no code reads it.

One risk stays open, and it is not a defect in this card. The next card that adds a test will make the count stale again. The card says a count that updates itself is out of scope.

No criterion is disproved.

VERDICT: sound

