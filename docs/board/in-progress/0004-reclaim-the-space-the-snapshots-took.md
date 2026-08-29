---
needs: 0001
---

# Reclaim the space the snapshot tables took

## Why
Truncating `member_equipment_snapshots` brought the database from 3072 MB back to 1695 MB, but
InnoDB does not return freed pages to the size meter on its own, and the meter is what Hostinger
enforces the cap against. Two further reductions are named in the runbook and neither has been
done: `member_snapshots.raw_json` is ballast no widget reads, and an `OPTIMIZE TABLE` pass is what
actually hands the pages back.

Both need write grants, which is why this card cannot start until card 0001 confirms they are back.

## Not this card
Preventing regrowth. That is cards 0002 and 0003.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN the database size is measured after this work, IT SHALL be materially below the
      1695 MB the truncation left, and the figure SHALL be recorded in the runbook.
- [ ] #2 THE `member_snapshots.raw_json` column SHALL be dropped, and no widget or importer SHALL
      read it, proved by a grep before the migration rather than after.
- [ ] #3 `OPTIMIZE TABLE` SHALL have run against `member_snapshots` and the other snapshot tables
      once the prune has run at least once.
<!-- AC:END -->

## Tasks
- [x] Grep for every read of `raw_json` before dropping it
- [ ] Drop the column in a reversible migration
- [ ] Run `OPTIMIZE TABLE` and record the before and after sizes in the runbook

## Plan
Do the grep first and do it honestly: `raw_json` is the kind of column something reads once, in a
command nobody runs often, and the migration that drops it is the one that finds out.

## Comments

**2026-08-29** Did the grep. It says do not drop the column, so no migration was written and no
criterion is ticked. All three are still open, for two separate reasons.

**The grep, and what it found.** `member_snapshots.raw_json` has four live readers, not the two the
runbook listed:

- `resources/views/components/weekly-key-cell.blade.php:21` reads
  `raw_json['mythic_plus_weekly_highest_level_runs']` to build the M+ weekly-key popover. That is a
  widget, rendered on three screens (`dashboard/widgets/team-roster.blade.php:128`,
  `dashboard/keynight.blade.php:74`, `dashboard/character/show.blade.php:114`). The card's `## Why`
  says "ballast no widget reads"; that premise is false, and this reader is the one that makes it
  false.
- `App\Services\Bis\BisComparisonService::rawArray` (line 623) — gear fallback off the latest RIO row.
- `App\Services\Grm\GrmSnapshotDiffer::diffMemberSnapshots` (lines 249-250) — reads `note`,
  `officerNote` and `customNote.3` off the previous GRM row to emit note-changed events.
- `App\Console\Commands\BackfillMplusRuns` (lines 62-69) — walks `raw_json` on **every** stored row,
  not just the latest, to recover historical M+ runs.

Dropping the column breaks all four. Criterion #2 cannot be met as written, and I have not reworded
it. The full reader table is now in `docs/ops-runbook.md`, follow-up 2, replacing the wrong
two-reader note that sent this card in.

**What I did not decide.** The runbook's own alternative — keep `raw_json` only on each member's
latest row — is still viable and is where this card should go next, but it is not what criterion #2
asks for and it costs something a person owns: it retires `BackfillMplusRuns`, whose whole job is
reading old rows. Whether that history is still worth keeping is Rob's call, not mine, so I stopped
rather than build a different card.

**Criteria #1 and #3 need production, which this session cannot reach.** Both are measurements and
an `OPTIMIZE TABLE` against the Hostinger database. `.env` here has `DB_HOST=127.0.0.1`, which is
the local Herd MySQL, not Hostinger; the runbook's own procedure reaches production over SSH. So
there is no before-and-after figure to record and no `OPTIMIZE` to run from here. Note also that
`OPTIMIZE TABLE` on the live database is an effect no `git revert` reaches, which by the board's own
test in `docs/board/README.md` makes it a person's step. I have not added `not_for_the_loop:` to the
frontmatter myself, because that changes what the scheduler does with the card; flagging it is what
I can do.

**Prerequisite state, for the next reader.** Card 0001 is still in `todo/` with every box unticked,
but its thread records a successful login and save on 2026-08-20, so writes are probably back. I did
not treat "probably" as confirmation.

**Suite and style.** Nothing here changed application code; the only edits are this card and the
runbook. `.\vendor\bin\pest.bat` is green: 705 passed, 2176 assertions. `.\vendor\bin\pint.bat` is
**not** clean at baseline and was reverted: run bare it rewrote 190 PHP files this card never
touched, none of them mine, all pre-existing drift. Committing that would have buried a two-file doc
change under a repo-wide reformat, so `git checkout` put them back. Worth a card of its own — either
adopt the reformat in one commit or pin the Pint preset — but it is not this one.
