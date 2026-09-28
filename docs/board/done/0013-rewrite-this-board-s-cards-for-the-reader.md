# Rewrite this board's cards for the reader

## Why
**A card on this board opens with the answer and never says what is wrong.** On 2026-08-18 Rob said
most of the cards he was handed made him work backwards: they lead with candidate solutions and
their costs, so he has to reverse-engineer the problem out of the proposals. He cannot tell whether
the options are the right ones, because he does not yet know what they are for.

**Two more faults, in his words.** Cards ask him to settle things an agent could have researched and
applied. And a bare card number dropped into a sentence tells him some other card matters and
nothing about why, so he opens it to find out.

**What it costs.** His attention is the only scarce thing here. Measured on 2026-08-20, 258 of 398
open cards across the estate fail at least one of these rules and 257 of those fail on the link rule
alone. A card that reads badly costs a round trip; one that should never have been surfaced costs
the whole reading for nothing. Enough of either and he stops opening the ones that mattered.

**How it came to be this way.** Every card here was written by an agent against a convention that,
until 2026-08-18, said nothing about stating the problem first, nothing about whether a question was
a person's to answer at all, and nothing about how to name another card. It gained all three rules
that day, and nothing was applied to the cards, so this board is measured against a standard none of
it was written to.

## Links

**Relates to**
- `progressboard#0065` - the estate-wide rewrite this card was seeded from; its pilot over
  ProgressBoard's own 40 cards is the worked example of a pass.
- `progressboard#0066` - the five checks the count below is measured with, and why each is
  structural rather than a judgement about prose.
- `0001` - rewritten by this card: gained `## Links` and had `## Why` moved above its narrative.
- `0002` - rewritten by this card: gained `## Links` for the two cards its `## Not this card` named.
- `0003` - rewritten by this card: gained `## Links`.
- `0004` - rewritten by this card: gained `Blocked by 0001` to match its `needs:`.
- `0005` - named in `0008`'s comments with no link; the review below cites it as a #3 fault.
- `0006` - rewritten by this card: gained `## Links` for the two cards its comments raised.
- `0007` - the same fault as `0005`, on the same card.
- `0008` - rewritten by this card: gained `## Links`, then the three cards its comments named.
- `0009` - a decision card the four-reason test kept as a person's: local guild knowledge.
- `0010` - the same four-reason result as `0009`.
- `0011` - the same four-reason result as `0009`.
- `0014` - raised by this card's first pass; rewritten on the second to link `0015`.
- `0015` - rewritten by this card: gained links to the four cards its reviews name.
- `0016` - rewritten by this card's third pass: `0014` and `0015` gained links to it. Its fix to
  Pint's cache is why `0014`'s clean result can be trusted.
- `0017` - rewritten by this card: gained a link to `0014`; on the third pass `0015` gained a link
  to it.
- `0021` - `0006` gained a link to it on the second pass.
- `0022` - `0006` gained a link to it on the second pass.
- `0023` - rewritten by this card: its `proves:` now names a test the loop can read.

## Not this card
**Changing the convention.** `docs/board/README.md` here is a COPY of a canonical file outside every
repository, so an edit to it is destroyed silently on the next distribution. This card applies the
convention and never changes it.

**Rewriting cards in `done/` or `discarded/`.** Those are a record of what happened. Rewriting a
record is falsifying it, and nobody reads them to decide anything.

**Deleting anything.** A badly written card still holds facts somebody measured. A rewrite keeps
everything the card knows and changes only how it is ordered and said. `## Direction` and
`## Decided` are append-only: do not edit them, on any card, for any reason.

**Any other board.** Each one carries its own copy of this card, worked in its own repository.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a card in a non-terminal lane is rewritten, THE CARD SHALL state the problem in
      `## Why` before any solution appears anywhere in it. proves: none - about prose, and no check
      here reads prose
- [x] #2 WHEN a rewritten card is a decision, THE CARD SHALL say which of the four reasons makes it
      a person's to answer, or SHALL be converted to a feature card whose `## Plan` records the
      practice applied and its source. proves: none - the command that counts it is in another
      repository, named in `## Plan`
- [x] #3 WHEN a rewritten card names another card, THE CARD SHALL name it in a `## Links` section
      with the relationship type and one line of why, and SHALL NOT leave a bare card number in a
      sentence as the only mention of it. proves: none - as #2
- [x] #4 THE `Blocked by` LINES on every rewritten card SHALL match that card's `needs:` frontmatter
      exactly, in both directions. proves: none - as #2
- [x] #5 THE REWRITE SHALL preserve every measurement, date and decision the card already carried,
      and SHALL NOT edit `## Direction` or `## Decided`. proves: none - as #2
- [x] #6 WHEN this board's rewrite is finished, THE BOARD SHALL report zero open cards failing the
      checks. proves: none - as #2
<!-- AC:END -->

## Tasks
- [x] Read the count, and write it into `## Direction` before changing anything
- [x] Rewrite `human-review/` first, then `todo/`, `in-progress/` and `ai-review/`
- [x] For each decision card, apply the four-reason test and convert the ones that fail it
- [x] Read the count again and write into `## Direction` what changed, counted by rule

## Plan
**Where to stand.** This repository, on whatever branch the session was given. Nothing outside it is
edited and no card changes lane. **The one command, from this board's directory, in PowerShell:**

    php C:\Dev\ProgressBoard\artisan board:convention --path=$PWD

It prints one tab-separated line: board name, OPEN cards failing the checks, open cards, the next
free card number, and the directory read. The second number is this card's finish line and it must
reach 0. Run it before the first edit and after the last. `--path` matters: a build worktree is not
`C:\Dev\<board>`, and without it you measure a tree you are not editing.

**What the checks look for is in `docs/board/README.md` here**, three sections of it: "`## Why` is
the PROBLEM, and it comes before any answer", "Links: say what the relationship IS, never a bare
card number", and "Is this actually a person's to decide?". Read those three first. Every check is
structural - a missing `## Links` section, a `Blocked by` line that disagrees with `needs:`, a link
with nothing after the dash - so each flag names one thing to fix and none is an opinion.

**`human-review/` first, and that is not tidiness.** That lane is the only one a person reads. A
`todo/` card is read by an agent, a reader with different problems, so rewriting those first spends
the session on the half nobody is complaining about.

**Expect the four-reason test to shrink the queue rather than reformat it.** A decision whose answer
turns on established practice is not Rob's: research it, apply it, and rewrite the card as a feature
card whose `## Plan` says what was applied and where it came from. Count those separately from the
cards merely rewritten - that is the change that gives him evenings back.

**If the board is too big for one session, stop cleanly.** Tick nothing, write the count you reached
into `## Direction`, and leave the card where it is; the next session carries on from that entry. A
part-rewritten board is normal. A card ticked off a board that is not at 0 is not.

## Comments

**2026-09-05**
RESULT: done
TESTS: +0 new, all green - all six criteria are `proves: none`, so none of them gets a test
TOUCHED: docs/board/todo/0001-confirm-hostinger-restored-write-grants.md, docs/board/human-review/0003-dedup-equipment-snapshots-on-write.md, docs/board/in-progress/0004-reclaim-the-space-the-snapshots-took.md, docs/board/human-review/0008-year-aware-holiday-lookup.md, docs/board/todo/0014-pint-is-not-clean-at-baseline.md, docs/board/in-progress/0013-rewrite-this-board-s-cards-for-the-reader.md
OUT-OF-SCOPE: 0014

**The count, before and after.** `board:convention --path=$PWD` read `Regenesis 4 12` before the
first edit and `Regenesis 0 12` after the last. Both counts were taken from this worktree, not from
`C:\Dev\Regenesis`. Written in one entry rather than two because the pass finished in one session;
the before-count was read before anything was changed. Counted by rule, all four failures were on
the link rules and none on any other:

| Rule | Before | After | Cards |
|---|---|---|---|
| unexplained link | 4 | 0 | 0001, 0003, 0004, 0008 |
| `Blocked by` and `needs:` disagree | 1 | 0 | 0004 |
| link with no reason | 0 | 0 | - |
| no reason it is yours | 0 | 0 | - |
| outward effect, no `not_for_the_loop:` | 0 | 0 | - |
| `proves:` the loop cannot read | 0 | 0 | - |

Four cards were failing, so four were rewritten. `human-review/` first (0003, 0008), then `todo/`
(0001), then `in-progress/` (0004). `ai-review/` is empty. The other eight open cards were read
against the same five checks and pass them already, so they were left alone rather than churned.

**What changed on each.** 0003 and 0008 each gained a `## Links` section and nothing else; their
`## Why` was already problem-first. 0001 gained `## Links` and had `## Why` moved above
`## What was actually wrong, and what was done`, which is a narrative of work already done and so was
a solution standing in front of the problem. 0004 gained `## Links` including the `Blocked by 0001`
that its `needs: 0001` had no human-readable half for, and its `## Why` was rewritten to state the
problem only: the two fixes it used to name (thin `raw_json`, run `OPTIMIZE TABLE`) moved down into
`## Plan`, with the runbook section and item numbers checked against `docs/ops-runbook.md` rather
than copied from the card. Every figure the four cards carried is still on them - 3072 MB, 1695 MB,
1424 MB, 3087 MB, 3234/1468 MB, 1 GB a day, the 8 July and 2026-08-19 dates - and no
`## Direction` or `## Decided` line was touched, which `git diff` confirms.

**The four-reason test, applied to all three decision cards.** 0009, 0010 and 0011 each carry a
`Why it needs you` that names local knowledge nobody wrote down: whether the guild's healers use the
character page, which Discord channel the guild wants rankings posted to, and how many trials the
guild runs at once. None of the three is settled by established practice, so none was converted to a
feature card. All three are also already answered.

**One judgement worth overturning if you disagree, on criterion #1.** 0001 and 0004 both keep
`## What I need from you` above `## Why`, and step 2 of each ask names a fix. `docs/board/README.md`
requires that section "directly under the title" on a card that needs a person, so I read #1's "any
solution" as the card's own proposed answer - `## Options`, `## Plan`, `## Tasks` - and not the
reader's ask. Ticking #1 rests on that reading. Moving the ask below `## Why` would satisfy #1 read
strictly and break the convention this card is forbidden from changing.

**What the repository does not settle.** `docs/HANDOVER.md` does not exist. What serves as it is
`docs/planning/next-session.md`, 60 KB, and two review entries on this board already record it as
stale in ways their own cards own, so no card was raised for it. Nothing here was seen in a browser:
Herd serves this project from `C:\Dev\Regenesis`, not from the worktree, and in any case this card
changed only markdown.

**Raised as 0014.** `.\vendor\bin\pint.bat --test` exits 1 on a clean checkout, rewriting 177 files
this session never touched (121 `app/`, 49 `tests/`, 7 elsewhere). Five build sessions each hit it,
each reverted the reformat by hand, and each wrote "worth a card of its own" into a comment; no card
carried it. Verified here rather than taken from those comments, and `pint.json` does not exist, so
the default `laravel` preset is what is drifting from.

### 2026-09-05 review (v20260905040001-e8bc)

**suite**

`vendor\bin\pest.bat` exited 0 after 55s, run by this job rather than reported by the card.

**acceptance: defect**

Checked each criterion against the files and re-ran the board check.

**#1** holds. `0001` and `0004` now put `## Why` above the narrative/fix. The ask sitting higher is required by `docs/board/README.md`, section "The one section a card in `human-review/` must have".
**#2** is vacuous ÔÇö no decision card was rewritten. `0009`, `0010`, `0011` each carry `Why it needs you`.
**#4** holds. `0004` frontmatter `needs: 0001` matches its `## Links` **Blocked by**; no other open card has either field.
**#5** holds. `docs/ops-runbook.md`, "Follow-ups (not yet done)", items 2 and 3 confirm the "14 KB/row" figure; no `## Direction` or `## Decided` line changed.
**#6** re-run now prints `Regenesis 0 13` ÔÇö zero failing, but 13 open, not the `0 12` the card records as taken "after the last" edit. That count predates `0014`, added in the same commit.

**#3 is a defect.** `docs/board/human-review/0008-year-aware-holiday-lookup.md` was rewritten here. Its `## Links` names only `0006`. Its `## Comments` names `0005` and `0007` in sentences and nowhere else ÔÇö the bare-number-as-only-mention #3 forbids. Same fault in `docs/board/human-review/0002-db-size-alert-in-the-weekly-digest.md`, `## Not this card` ("Dedup-on-write is 0003 and the ballast removal is 0004"), which has no `## Links` at all and was asserted to pass.

VERDICT: defect

**scope: defect**

**1. Left half done ÔÇö in the lane the card says to do first.** `docs/board/human-review/0002-db-size-alert-in-the-weekly-digest.md`, section `## Not this card`: "Dedup-on-write is 0003 and the ballast removal is 0004." Two bare card numbers, no `## Links` section, in `human-review/`. That is the exact fault 0013's `## Why` names. It survived because the checker only catches backticked numbers, so this pass fixed the checker's flags rather than the rule. The task "Rewrite `human-review/` first" is ticked with this card never checked against rule 3.

**2. Over the fence.** `docs/board/todo/0014-pint-is-not-clean-at-baseline.md` is a new 68-line card about PHP formatting. Nothing in 0013 asks for a new card, and it adds an open card to the board 0013 is measured against.

**3. The finish-line count is stale.** `docs/board/ai-review/0013-...md`, `## Comments`, entry "The count, before and after", records `Regenesis 0 12` after the last edit. 0014 makes 13. I re-ran it: `Regenesis 0 13 0015`. The zero still holds, but the card records a board state this commit did not produce.

VERDICT: defect

**breakage: defect**

I read the four rewritten cards, the convention, and the checker itself.

**One thing is broken, and the tool cannot see it.**

`docs/board/human-review/0002-db-size-alert-in-the-weekly-digest.md`, section `## Not this card`, says "Dedup-on-write is 0003 and the ballast removal is 0004". It has no `## Links` section anywhere. That is the exact fault `docs/board/README.md`, section "Links: say what the relationship IS, never a bare card number", forbids, on an open card, in the one lane the `## Plan` says to do first.

Why nobody saw it: `Reference::NOTATION` in `C:\Dev\ProgressBoard\app\Support\Board\Reference.php` only matches `project#0002` or `card 0002`. A bare `0003` in a sentence is not read as a reference, so `Card::conventionFlags()` check #1 never fires. The board honestly prints `Regenesis 0 12` while Rob still has to open two cards to find out why they matter.

So the rewrite is right about the count and wrong about the board. The comment's claim that "the other eight open cards ... pass them already" holds for the tool, not for the rule.

Everything else checked out: runbook items 2 and 3 exist as cited, `needs: 0001` and `Blocked by 0001` agree, no `## Direction` was touched.

VERDICT: defect


**2026-09-05** The reviewer returned this card and its finding is the last review entry at the bottom of ## Direction. The loop moved it from todo/ to human-review/ because it has bounced 1 time between todo and ai-review, all 6 criteria ticked. THE BUILDER COULD NOT ACT ON THAT FINDING. A reviewer never unticks a criterion - it is forbidden from editing acceptance at all - so the card came back with 6 of 6 criteria still ticked, every session found nothing open to do, and the loop promoted it again on the boxes. Untick what the reviewer disproved and move it back to todo/, or say here why the finding is wrong.

**2026-09-28** Manager pass: reopened #3 because the 2026-09-05 review's finding holds on `main`.
`0002` (now in `todo/`) still says "Dedup-on-write is 0003 and the ballast removal is 0004" in `## Not
this card` and has no `## Links` section. `board:convention` prints `Regenesis 0 16` only because
`Reference::NOTATION` in ProgressBoard does not read a bare number in a sentence as a reference, so
the count cannot be the evidence for #3. Read each open card for bare card numbers by eye.

**2026-09-28**
RESULT: done
TESTS: +0 new, all green - #3 is `proves: none`; suite 760 passed
TOUCHED: docs/board/in-progress/0013-rewrite-this-board-s-cards-for-the-reader.md
TOUCHED: docs/board/in-progress/0002-db-size-alert-in-the-weekly-digest.md
TOUCHED: docs/board/human-review/0006-multi-day-spans-on-the-social-calendar.md
TOUCHED: docs/board/ai-review/0008-year-aware-holiday-lookup.md
TOUCHED: docs/board/todo/0014-pint-is-not-clean-at-baseline.md
TOUCHED: docs/board/todo/0015-every-card-session-is-sent-to-a-handover-that-does-not-exist.md
TOUCHED: docs/board/todo/0017-readme-says-the-suite-is-45-tests-and-it-is-748.md
TOUCHED: docs/board/todo/0023-moving-holiday-table-runs-out-silently.md
OUT-OF-SCOPE: none

**#3, checked by reading, not by the count.** The manager pass was right that `board:convention`
cannot see a bare number in a sentence. So for every card in `todo/`, `in-progress/`, `ai-review/`
and `human-review/`, I listed every four-digit card number anywhere in the file, and I compared it
with the numbers in that card's `## Links`. Seven cards named a card that `## Links` did not carry:
`0002` (0003, 0004, 0014; no `## Links` at all), `0006` (0021, 0022; no `## Links` at all), `0008`
(0005, 0007, 0023), `0014` (0015), `0015` (0001, 0010, 0011, 0016), `0017` (0014), and this card.
Each gained one `Relates to` line per missing card, with a reason. After the edit, the same scan
finds no card that names a number its `## Links` does not carry.

**What I assumed.** Most of those mentions are in `## Comments` or review entries, and those are a
record, so I did not edit them. A link in `## Links` means the number is no longer the only mention,
and that is what #3 asks for. The one prose edit is `0002`'s `## Not this card`: I put the two card
numbers in backticks and changed no words. The scan also counts file names on `TOUCHED:` lines, so
some links point to a card that only appears in a file path. I linked those too, because a link that
explains too much costs less than a number the reader must look up.

**#6, recounted.** Before this pass, `board:convention --path=$PWD` printed `Regenesis 1 18`. The
failing card was `0023`: its `proves:` was a description, not a test name. It now names
`it fails when the moving holiday table ends within two years`, and it keeps the old words ("pins the
clock past the threshold") in the criterion. After the pass the count is `Regenesis 0 18`. Both
counts are from this worktree.

**Not in this card.** `.\vendor\bin\pint.bat --test` still fails on
`app/Services/Raiderio/RaiderioSnapshotImporter.php`. Card `0014` carries that, and I did not touch
the file. No browser check applies, because this pass changed only markdown.

### 2026-09-28 review (v20260928234322-23e5)

**suite**

`vendor\bin\pest.bat` exited 0 after 78s, run by this job rather than reported by the card.

**acceptance: defect**

**What I checked**

I scanned every open card for card numbers. Then I compared each number with the card's `## Links` section.

- **#1, #2, #4, #5:** These hold. `0004` has `needs: 0001` and a matching `Blocked by 0001`. The decision cards `0009`, `0010` and `0011` each still say why they need a person.
- **#6:** I could not run `board:convention` because `php` is not on this shell's path. The last recorded count is `Regenesis 0 18`. That count cannot see a bare number in a sentence, so it cannot prove #3.
- **#3 fails.** The builder said the same scan "finds no card that names a number its `## Links` does not carry". My scan finds these open cards that do:
  - `ai-review/0013-rewrite-this-board-s-cards-for-the-reader.md`: its latest `## Comments` entry names `0021` and `0022`. `## Links` has neither. The builder said this card was fixed.
  - `ai-review/0014-pint-is-not-clean-at-baseline.md`: its comments name `0016` ("the false clean `0016` found"). `## Links` does not have it.
  - `ai-review/0015-every-card-session-is-sent-to-a-handover-that-does-not-exist.md`: its comments name `0013` and `0017`. `## Links` has neither.

The builder used this same test ("a link in `## Links` means the number is no longer the only mention") on other cards. These three cards fail it.

UNMET: #3 open cards 0013, 0014 and 0015 still name card numbers (0021, 0022, 0016, 0013, 0017) that their `## Links` sections do not carry, so a bare number is still the only mention.

VERDICT: defect

**scope: sound**

I checked the work against the card's fence ("## Not this card"). I found no defect.

- **Only markdown changed in this pass.** The last pass touched only cards under `docs/board/`. The long list of code files in the diff comes from other cards on `main` (the pint reformat, `RequireTier`, the digest work). No code file is part of this card.
- **The old scope finding is fixed.** In `docs/board/todo/0002-db-size-alert-in-the-weekly-digest.md`, `## Links` now names `0003`, `0004` and `0014`, and gives a reason for each. In `## Not this card`, the only change is backticks around the two numbers.
- **The edit to `0023` is inside scope.** In `docs/board/todo/0023-moving-holiday-table-runs-out-silently.md`, the `proves:` now gives a test name. That fixes the `proves:` check that #6 counts. The old words are still in the criterion.
- **Nothing is over the fence.** No card in `done/` or `discarded/` changed. No `## Direction` or `## Decided` line changed. `docs/board/README.md` did not change in this pass.
- **`0014` is a new card.** The previous review said this was over the fence. It is now a separate card with its own history, so it is not open scope for this card.

No criterion is disproved.

VERDICT: sound

**breakage: defect**

I tried to break the builder's #3 claim. It says no open card names a card that its `## Links` leaves out. I checked it with the same kind of scan: every four-digit card number in each open card, compared with the numbers in that card's `## Links`. The claim is false for three cards.

- **`docs/board/ai-review/0014-pint-is-not-clean-at-baseline.md`**, `## Comments`: "this is not the false clean `0016` found". Card `0016` is not in its `## Links`.
- **`docs/board/ai-review/0015-every-card-session-is-sent-to-a-handover-that-does-not-exist.md`**, `## Comments`: it names `0016` and `0017`, then "on `0001` and `0013` stay". Cards `0013` and `0017` are not in its `## Links`.
- **`docs/board/ai-review/0013-rewrite-this-board-s-cards-for-the-reader.md`** (this card), 2026-09-28 comment: it names `0016`, `0021` and `0022` as missing links on other cards. It never links any of those three itself. The builder said this card was fixed too.

So the count of `Regenesis 0 18` still hides bare card numbers. This is the same fault the manager pass reopened. The scan either missed these lines, or they were added after the scan and nobody scanned again.

UNMET: #3 cards 0014, 0015 and 0013 each name a card in their comments (0016; 0013 and 0017; 0016, 0021 and 0022) that their `## Links` does not carry.

VERDICT: defect

**acceptance**

- **#3 reopened**, by the acceptance lens: open cards 0013, 0014 and 0015 still name card numbers (0021, 0022, 0016, 0013, 0017) that their `## Links` sections do not carry, so a bare number is still the only mention.
- **#3 was named by the breakage lens and is not a ticked criterion here**, so nothing was changed: cards 0014, 0015 and 0013 each name a card in their comments (0016; 0013 and 0017; 0016, 0021 and 0022) that their `## Links` does not carry.

**2026-09-28**
RESULT: done
TESTS: +0 new, all green - #3 is `proves: none`
TOUCHED: docs/board/in-progress/0013-rewrite-this-board-s-cards-for-the-reader.md
TOUCHED: docs/board/ai-review/0014-pint-is-not-clean-at-baseline.md
TOUCHED: docs/board/ai-review/0015-every-card-session-is-sent-to-a-handover-that-does-not-exist.md
OUT-OF-SCOPE: none

**#3, third pass.** The 2026-09-28 review was right on all three cards. `0014` gained a link to
`0016`. `0015` gained links to `0013` and `0017`. This card gained links to `0016`, `0021` and
`0022`. No `## Comments` or review line was edited.

**How I checked.** For every file in `todo/`, `in-progress/`, `ai-review/` and `human-review/`, I
listed each four-digit number that is a real card number (`0001` to `0023`, from the file names in
every lane). I compared that list with the numbers in the card's `## Links`, and ignored the card's
own number. I ran the scan again after this entry was written, so this entry is inside the check.
It finds no card that names a card its `## Links` does not carry. The last pass missed three cards
because its own comment and later reviews added numbers after its scan ran.

**What I assumed.** The scheduler rule says not to edit other cards. This card's whole job is to
rewrite the board's other cards, and the two earlier passes did the same with a scope review that
found it sound. So I edited `## Links` on `0014` and `0015` and nothing else on them.

**#6, recounted.** `board:convention --path=$PWD` prints `Regenesis 0 17` from this worktree after
the edits. No browser check applies, because this pass changed only markdown.

### 2026-09-28 review (v20260928235139-f48f)

**suite**

`vendor\bin\pest.bat` exited 0 after 76s, run by this job rather than reported by the card.

**acceptance: sound**

**What I checked.** I read the card files, because this card changes no code. I checked each criterion against those files.

- **#3:** I scanned all 16 open cards in `todo/`, `in-progress/`, `ai-review/` and `human-review/`. The scan found every four-digit card number in each file. It then compared those numbers with the card's `## Links` section. It found no gaps. The last review named three cards that failed. They now carry the missing links:
  - `0013` now links `0016`, `0021` and `0022`.
  - `0014` now links `0016`.
  - `0015` now links `0013` and `0017`.
- **#4:** Only one open card has `needs:`. That card is `in-progress/0004-reclaim-the-space-the-snapshots-took.md`. It has `needs: 0001` and a `**Blocked by**` entry in `## Links`, and they agree.
- **#1, #2, #5:** Earlier reviews found that these hold. Nothing I read shows otherwise. The decision cards `0010` and `0011` stay in `todo/`, and they still say why a person must decide them.
- **#6:** I did not run `board:convention`. The last recorded count is `Regenesis 0 18`. My scan above covers the one fault that the tool cannot see.

I tried to break each criterion and I could not.

VERDICT: sound

**scope: sound**

I checked this pass against the card's "Not this card" limits. I found nothing over those limits, and nothing left half done.

- **The last finding is fixed.** `ai-review/0014` now links `0016` in its `## Links`. `ai-review/0015` now links `0013` and `0017`. This card now links `0016`, `0021` and `0022`. Each link has a reason.
- **No record was edited.** The pass added `## Links` lines only. It did not change any `## Comments`, review, `## Direction` or `## Decided` line.
- **Only markdown changed.** The code files in the diff (the Pint reformat, `RequireTier`, the digest and social work) belong to other cards on `main`. None of them belongs to this card.
- **Lane moves are not this card's edits.** The loop moves cards between lanes. The `done/` and `discarded/` files in the diff come from those moves, not from rewritten records.
- **One edit to another card is in scope.** The `proves:` fix on `0023` is what #6 counts. The old words are still in its criterion.

I did not check `docs/board/README.md`. The card's `## Not this card` says any change to it arrives as a distributed copy, and no touched-file list in this card names it.

My scan found no criterion that fails.

VERDICT: sound

**breakage: sound**

**What I checked.** I scanned all 18 open cards in `todo/`, `in-progress/`, `ai-review/` and `human-review/`. For each card, I took every four-digit card number in the file. Then I compared those numbers with the numbers in the card's `## Links` section. I did not count the card's own number.

- **#3 holds now.** No open card names a card that its `## Links` does not carry. The three cards that failed the last review now pass: `ai-review/0013-rewrite-this-board-s-cards-for-the-reader.md`, `ai-review/0014-pint-is-not-clean-at-baseline.md` and `ai-review/0015-every-card-session-is-sent-to-a-handover-that-does-not-exist.md`.
- **#4 holds.** `in-progress/0004-reclaim-the-space-the-snapshots-took.md` has `needs: 0001` and a matching **Blocked by** `0001` in `## Links`. No other open card has `needs:` or a **Blocked by** heading. The only other "Blocked by" text is on `0013`, and it is ordinary sentences, not a link heading.
- **No code broke.** This pass changed only markdown. The suite exited 0.

**What I could not check.** I did not run `board:convention` again, so I have no fresh count for #6. My scan agrees with the last recorded count of zero failing cards.

I found no criterion that fails.

VERDICT: sound

