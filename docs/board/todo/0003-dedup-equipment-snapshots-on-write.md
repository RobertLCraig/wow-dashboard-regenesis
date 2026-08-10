# Dedup equipment snapshots on write

## Why
`member_equipment_snapshots` reached 1.38 GB by storing a fresh 43 KB gear blob per member per
pull, and gear changes rarely. It was the single largest contributor to the 8 July outage.

The existing `snapshots.payload_hash` dedup does not help here: it hashes the whole batch, and the
batches rotate through the 100 stalest members per run, so the hash almost never matches. The dedup
exists at the wrong granularity rather than being absent, which is why the table grew despite it.

## Not this card
Reclaiming the space already used, which is card 0004, and the alerting, which is 0002.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a member's gear is unchanged since their last snapshot, THE IMPORTER SHALL NOT write a
      new row.
- [ ] #2 WHEN a write is skipped, THE IMPORTER SHALL still record that the member was checked, so
      `EquipmentSnapshotImporter::selectMembersToFetch` keeps rotating rather than fetching the same
      hundred members forever.
- [ ] #3 WHEN a member's gear changes, THE NEXT SWEEP SHALL write a new row, proved by changing one
      item and running the importer.
<!-- AC:END -->

## Tasks
- [ ] Add a per-member content hash to the equipment snapshot row
- [ ] Skip the write on an unchanged hash, and update the checked-at timestamp regardless
- [ ] Prove criterion #2 by running two consecutive sweeps and watching which members are selected

## Plan
Criterion #2 is the trap the runbook flags by name. `selectMembersToFetch` orders by `captured_at`,
so a skipped write that leaves `captured_at` alone makes those members permanently the stalest, and
the importer stops making progress while looking like it is working.
