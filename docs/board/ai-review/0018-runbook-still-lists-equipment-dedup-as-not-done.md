# Runbook still lists the equipment dedup as not done

## Why
`docs/ops-runbook.md`, section "Follow-ups (not yet done — further headroom, in priority order)",
item 1 asks for dedup-on-write for `member_equipment_snapshots` with a per-member hash column. Card
0003 built that dedup, without a hash column, in `EquipmentSnapshotImporter::pull`. The next reader
of the runbook is told to build it again, and in a shape the card rejected because a migration
cannot run while production writes are revoked.

It happened because card 0003 changed the importer and nobody went back to the runbook.

## Links

**Relates to**
- `0003` - built the dedup this runbook item still asks for; its `## Plan` says why there is no hash
  column.

## Not this card
Any code change. Any other runbook item.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a reader opens the runbook follow-ups, THE RUNBOOK SHALL no longer list equipment dedup
      as not done, and SHALL say where it was built. proves: none - a doc edit, nothing to run
<!-- AC:END -->

## Tasks
- [x] Move item 1 out of "not yet done" and point it at `EquipmentSnapshotImporter::pull` and card 0003

## Comments

**2026-09-28**
RESULT: done
TESTS: +0 new, all green (AC #1 is proves: none)
TOUCHED: docs/ops-runbook.md
TOUCHED: docs/board/in-progress/0018-runbook-still-lists-equipment-dedup-as-not-done.md
OUT-OF-SCOPE: none

The dedup entry now sits under "Prevention (shipped)", pointing at `EquipmentSnapshotImporter::pull`
and card 0003, and saying why there is no hash column (checked against 0003's Plan). The follow-ups
keep their numbers 2 and 3, with a one-line note on where item 1 went: card 0004 cites "follow-up 2"
three times for the `raw_json` table, and renumbering would have broken that and touched items this
card rules out. No browser check applies; it is a doc.
