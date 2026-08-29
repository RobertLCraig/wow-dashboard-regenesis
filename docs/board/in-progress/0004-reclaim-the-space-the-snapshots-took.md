---
needs: 0001
not_for_the_loop: the work left is an OPTIMIZE TABLE on the live Hostinger database
---

# Reclaim the space the snapshot tables took

## What I need from you

**Two things.**

1. **Reclaim the space on the live database yourself.** Nothing here can reach that host.
2. **Decide whether the old `raw_json` archive is still worth keeping.** One command below tells you.

---

**On 1.** Follow [docs/ops-runbook.md](../../ops-runbook.md), "site is 500ing", steps 3 and 4. Read
the hPanel figure before you start and again after, and write both into the runbook. That is the
whole of criteria #1 and #3.

Pass is both: the hPanel figure is materially under 1695 MB, and both figures are in the runbook,
dated. Fail is `OPTIMIZE TABLE` erroring on a missing grant, which means the write grants never came
back; say so here and go back to card `0001`.

**Why it needs you** It runs against the live database, and no `git revert` reaches it.

**On 2.** Criterion #2 says drop `member_snapshots.raw_json`. It cannot be: four things read it,
listed in the runbook's follow-up 2. The runbook's alternative is to keep the column only on each
member's newest row and null it on the rest. That suits three of the four readers and retires
`mplus:backfill-runs`, whose whole job is walking the old rows.

That command is a one-way extractor. It lifts M+ runs out of the old blobs into `member_mplus_runs`,
and re-running it only bumps `last_seen_at`. So the question is just whether it has already finished.
On production:

    php artisan mplus:backfill-runs --dry-run

- **"0 runs would persist"** — the archive is already emptied into `member_mplus_runs`, so nulling
  the old blobs costs nothing. Answer: null them.
- **Any number above zero** — the archive still holds runs nothing has recovered. Run it for real
  first, then answer.

**Why it needs you** The cost is yours: nulling the old blobs gives up any *future* field we might
one day want back out of them, and only you know whether that matters.

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

**2026-08-29** Second unattended run, nothing built, no criterion ticked. I re-ran the grep rather
than trusting the entry above and it holds, so criterion #2 is still false as written. `.env` here
reads `DB_HOST=127.0.0.1`, the local Herd MySQL, so #1 and #3 are still out of reach.

What is new is that the archive question is now cheap to answer. `mplus:backfill-runs` is idempotent
and one-way, so "is the old `raw_json` worth keeping" reduces to one dry run against production; the
new `## What I need from you` says what each answer means. I did not run it and did not answer it.

I also added `not_for_the_loop:`, which the run above flagged and left. The loop has now spent two
sessions on a card whose remaining work is on a live host. Delete that one line if you disagree — it
keeps the unattended loop off the card and grants nothing.

Suite green here: 705 passed, 2176 assertions. Pint run as `--dirty` and clean; this card touched
one markdown file and no PHP. The repo-wide Pint drift found above is still there and still not this
card's.
