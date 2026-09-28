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
- [ ] #1 WHEN a reader opens the runbook follow-ups, THE RUNBOOK SHALL no longer list equipment dedup
      as not done, and SHALL say where it was built. proves: none - a doc edit, nothing to run
<!-- AC:END -->

## Tasks
- [ ] Move item 1 out of "not yet done" and point it at `EquipmentSnapshotImporter::pull` and card 0003

## Comments
