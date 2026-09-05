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
- [x] #1 WHEN a session opens `docs/HANDOVER.md`, IT SHALL find the project's goal, its data shape,
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
