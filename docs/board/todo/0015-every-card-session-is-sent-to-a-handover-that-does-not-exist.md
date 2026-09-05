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
- [ ] #2 WHERE the repository does not settle something the handover asks for, IT SHALL say so in
      the document rather than leave the heading out.
      proves: none - the same, and the point is that the gap is visible to a reader
<!-- AC:END -->

## Tasks
- [ ] Write `docs/HANDOVER.md` from what the repository already shows
- [ ] Mark every heading the repository cannot answer as an open gap, in the document

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
