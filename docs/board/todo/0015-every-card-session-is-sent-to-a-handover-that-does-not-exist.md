# Every card session is sent to `docs/HANDOVER.md`, which has never existed

## Why
The unattended build loop hands each session the same opening instruction: read the card, then read
`docs/HANDOVER.md`. That file is not in this repository. `git log --all -- docs/HANDOVER.md` returns
nothing, so it has never been committed in any branch, and `docs/` holds `accessibility-guide.md`,
`ops-runbook.md` and a pile of vendor API dumps instead.

**What it costs.** Every session's second action fails. The handover is the half of the standard that
says what is TRUE - what the project is for, what the data looks like, which decisions are already
taken and how to pick the work up - while the board only says what is MOVING. Without it a session
knows only its own card, so it reconstructs the project from the code, and two sessions reading the
same code can reconstruct it differently. Nothing warns them: a missing file reads as "nothing to
know here" rather than as a gap.

**How it came to be this way.** `docs/board/` was added to this repository and the other half of the
standard was not. Nobody decided to leave the handover out; the board was the urgent half.

## Links

**Relates to**
- `0014` - the session that built it hit the missing file on its first read and had nothing to
  orient against, which is how this was found.

## Not this card
**Deciding anything.** The handover records what the repository already shows and what the decided
cards already say. A question it cannot answer from those is a gap to mark loudly in the document,
not one to settle here.

**Changing the board.** The lanes, the cards and `docs/board/README.md` are all correct and stay as
they are.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a session opens `docs/HANDOVER.md`, IT SHALL find the project's goal, its data shape,
      the decisions already taken, and how to run the suite and pick up work.
      proves: none - a document's content, which no test in this suite can read
- [x] #2 WHERE the repository does not settle something the handover asks for, IT SHALL say so in
      the document rather than leave the heading out.
      proves: none - the same, and the point is that the gap is visible to a reader
<!-- AC:END -->

## Tasks
- [x] Write `docs/HANDOVER.md` from what the repository already shows
- [x] Mark every heading the repository cannot answer as an open gap, in the document

## Plan
**Where to stand.** This repository, on whatever branch the session was given, from the repository
root in PowerShell.

**Use the skill rather than inventing a shape.** The `handover` skill holds the canonical shape and
the rule that gaps are made loud rather than hidden. Run it as `/handover save`. Its own description
is the spec, so nothing here restates it.

**What to read first, in this order:** `README.md`, `docs/board/README.md`, the cards in
`docs/board/done/` for decisions already taken, `composer.json` and `routes/web.php` for what the app
actually is, and `docs/ops-runbook.md` for how it is run.

**What "it worked" looks like:** a new session handed only this repository can say what the project
is for and what to run, from that one file, without opening the code.

## Comments

**2026-09-05**
RESULT: done
TESTS: +0 new, all green (748 passed). Both criteria are `proves: none`, so there was no test to
write and the test-first rule does not apply to this card. The suite was run to prove nothing broke.
TOUCHED: docs/HANDOVER.md (new), docs/board/todo/0016-pint-with-no-path-checks-nothing-and-exits-clean.md (new), docs/board/in-progress/0015-every-card-session-is-sent-to-a-handover-that-does-not-exist.md (ticks and this entry)
OUT-OF-SCOPE: 0016

**What I built.** `docs/HANDOVER.md`, 15 KB, in the `handover` skill's canonical shape, every
section present. Ran the skill as the Plan said. Two of its `save` steps were declined on this
card's own scope fence, and both refusals are deliberate: step 0 copies the skill's
`board-README.md` over `docs/board/README.md` and reconciles lanes, and `## Not this card` says the
board and its README stay as they are. The skill's step 8 auto-checkpoint is the commit I was
already making.

**Where the content came from.** `README.md` for the surfaces and the flow, `routes/console.php` for
what the app does over a day, the four core migrations for the data shape (their inline comments are
the authority, not the column lists), `docs/ops-runbook.md` for the operational traps, and
`docs/planning/next-session.md` for the decisions that were never logged anywhere else.

**Gaps written into the document rather than guessed past**, which is criterion #2: there is no PRD,
so the goal is read back off the code and marked interim, and the project's success criteria are
marked `UNKNOWN, confirm with Rob` because nothing in the repository states them. There is no
`DECISIONS.md`, so the decisions section says it recovered its contents from three separate files.
There is no `DATA-MODEL.md` and no root `CLAUDE.md` orient tripwire. `docs/board/done/` is empty, so
the Plan's instruction to read the decided cards had nothing to read, and the document says so.

**What I could not settle from the repository.** What "done" would mean for this project as a whole.
Every card carries acceptance; the project carries none, and no amount of reading produces one. It
is marked as a gap and left to Rob.

**Not proven, and it needs saying:** nothing here was checked in a browser. Herd serves this project
from `C:\Dev\Regenesis` and not from the card worktree, so a browser check is still owed on any
card that wants one. This card wanted none.

**Why 0016 was raised.** Establishing the style baseline turned up a fault in the check itself:
`.\vendor\bin\pint.bat --test` with no path exits 0 having scanned zero files, while
`.\vendor\bin\pint.bat --test .` on the same tree exits 1 and names a real offender. That is the
mechanism behind card `0014` shipping a false clean, and it is outside this card's fence, so it is a
card and not a fix. The trap is also written into the handover's `How to pick up`, because a
document that prints the broken form would be worse than no document.

### 2026-09-05 review (v20260905050755-472a)

**suite**

`vendor\bin\pest.bat` exited 0 after 27s, run by this job rather than reported by the card.

**acceptance: sound**

I checked both criteria against the file and the code it describes.

**Criterion #1** ÔÇö traced to `docs/HANDOVER.md`, sections `## Goal & success criteria`, `## Canonical data shape`, `## Decisions locked`, `## How to pick up`. All four things the criterion names are present, and the content is real, not plausible-sounding:

- The identity claim matches `unique(['guild_key','name'])` in `create_members_table`, and the enum list matches the comment block in `create_member_events_table`.
- The item-level order matches `RosterController::resolveIlvls` ("Blizzard -> Wowaudit -> RIO"); the BiS order matches `BisComparisonService::latestRawJson` callers.
- `RequireTier::handle` defaults to `User::TIER_OFFICER`, as stated.
- `PruneSnapshots::handle` does keep each member's latest per source.
- `.\vendor\bin\pest.bat` and `pint.bat` both exist and run.

**Criterion #2** ÔÇö every heading in the skill's canonical shape is present, and the four unsettled ones are marked loud (`## Sibling docs` MISSING rows, plus the GAP lines in `## Goal & success criteria` and `## Decisions locked`).

One inaccuracy, not a criterion failure: the `## How to pick up` TRAP says the bare Pint form checks nothing "in this repository". At `C:\Dev\Regenesis` it exits 1 and names the offender. The trap is real only in the ProgressBoard worktree. The advice itself stays correct.

VERDICT: sound

**scope: defect**


**2026-09-05** The reviewer returned this card and its finding is the last review entry at the bottom of ## Direction. The loop moved it from todo/ to human-review/ because it has bounced 1 time between todo and ai-review, all 2 criteria ticked. THE BUILDER COULD NOT ACT ON THAT FINDING. A reviewer never unticks a criterion - it is forbidden from editing acceptance at all - so the card came back with 2 of 2 criteria still ticked, every session found nothing open to do, and the loop promoted it again on the boxes. Untick what the reviewer disproved and move it back to todo/, or say here why the finding is wrong.

## Scope review ÔÇö card 0015

**Over the fence.** `## Not this card` says *"Changing the board. The lanes, the cards and `docs/board/README.md` are all correct and stay as they are."* The session added a new 92-line card, `docs/board/todo/0016-pint-with-no-path-checks-nothing-and-exits-clean.md`. That changes the board. It was declared (`OUT-OF-SCOPE: 0016`) and it follows the repo's own habit, so it did not grow quietly ÔÇö but it is still work the card did not ask for.

**Left half done, and this is the defect.** Having added 0016 to `todo/`, the session did not carry it into the document it was writing. In `docs/HANDOVER.md`, section `## What's next (in order)` lists only `0001`, `0010` and `0011`, and the same section counts "fourteen cards in work and person lanes" when the tree that commit left holds fifteen. `0016` is the only card in `todo/` an agent can actually build ÔÇö `0001` is a person's and `0010`/`0011` are decisions ÔÇö so the one section a picking-up session reads sends it past the single buildable card and tells it "mostly find things waiting on Rob". Criterion #1's "how to pick up work" is stale on the day it shipped.

VERDICT: defect

**breakage: defect**

Checked the new file against the repo: board lanes, `TIER_RANK`, `resolveIlvls`, the migrations, the Pint trap (`pint --test .` does exit 1 and does name `RaiderioSnapshotImporter.php`). Most of it holds. Two things do not.

**1. The queue sections hide card `0016`.** `docs/HANDOVER.md`, section "What's next (in order)", lists only `0001`, `0010` and `0011`, and says "`0001` blocks the only other card in a work lane". `0016` is in `docs/board/todo/`, is a work card, is not blocked, and was created by the same commit as this file. The same section's summary says "Of the fourteen cards in work and person lanes, two are in `todo/` as decisions and one is blocked" ÔÇö `todo/` holds four, and the lane total is fifteen. The file's own "How to pick up" section names `0016`, so it contradicts itself. A session that trusts "What's next" concludes nothing is buildable and skips the only card an agent can clear. That is the exact failure the card exists to stop.

**2. Two lane pointers go to the wrong place.** In "What's next" and in "Blockers / open questions", the links reading `docs/board/todo/` and `docs/board/human-review/` both target `board/`, not the named lane.

VERDICT: defect


**2026-09-28** Manager pass: reopened #1 because the 2026-09-05 scope and breakage finding holds on
`main`. `docs/HANDOVER.md`, `## What's next (in order)`, still names only `0001`, `0010` and `0011`,
still counts "fourteen cards in work and person lanes", and still says an agent "will mostly find
things waiting on Rob". So the one section a session reads to pick up work is wrong. Its two lane
links also point at `board/` rather than the lane they name. The fix should not be a new count:
point the section at `php C:\Dev\ProgressBoard\artisan board:order` and name no card that can
change lane.
