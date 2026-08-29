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
- [ ] Grep for every read of `raw_json` before dropping it
- [ ] Drop the column in a reversible migration
- [ ] Run `OPTIMIZE TABLE` and record the before and after sizes in the runbook

## Plan
Do the grep first and do it honestly: `raw_json` is the kind of column something reads once, in a
command nobody runs often, and the migration that drops it is the one that finds out.
