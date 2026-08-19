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
- [x] #1 WHEN a member's gear is unchanged since their last snapshot, THE IMPORTER SHALL NOT write a
      new row.
- [x] #2 WHEN a write is skipped, THE IMPORTER SHALL still record that the member was checked, so
      `EquipmentSnapshotImporter::selectMembersToFetch` keeps rotating rather than fetching the same
      hundred members forever.
- [x] #3 WHEN a member's gear changes, THE NEXT SWEEP SHALL write a new row, proved by changing one
      item and running the importer.
<!-- AC:END -->

## Tasks
- [x] Skip the write when the gear is unchanged, and keep the staleness clock moving anyway
- [x] Prove criterion #2 by running six consecutive sweeps and watching which members are selected

## Plan
Criterion #2 is the trap the runbook flags by name. `selectMembersToFetch` orders by `captured_at`,
so a skipped write that leaves `captured_at` alone makes those members permanently the stalest, and
the importer stops making progress while looking like it is working.

**No hash column, and no migration.** The card asked for a per-member content hash, but comparing
the decoded payload against the member's latest row in PHP settles it in one query and no schema
change. That matters more than usual right now: `INSERT` on the production database is revoked, so a
migration could not run and would abort the deploy at `deploy.sh` step 3.

Two writes had to move, not one:

1. **The row.** Unchanged gear re-points the member's existing row at the new snapshot instead of
   inserting a copy. That is the skip and the staleness update in the same statement.
2. **The snapshot.** `Snapshot::firstOrCreate` was handing back an old row, still carrying its
   original `captured_at`, whenever a batch hash recurred - and dedup makes recurring hashes the
   normal case rather than a rarity. Staleness is read off that column, so criterion #2 would have
   failed through this second door with the row logic entirely correct. Changed to `updateOrCreate`
   so re-observing the same gear moves the timestamp.

The six-run rotation test is what catches both: two members at `limit: 1` should be fetched three
times each, and either bug turns that into six and zero.
