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

## Comments

### 2026-08-29 review (v20260829154247-8f39)

**suite**

`vendor\bin\pest.bat` exited 0 after 60s, run by this job rather than reported by the card.

**acceptance: defect**

**AC #1 ÔÇö met.** `EquipmentSnapshotImporter::pull` compares the new payload against `latestRowsFor` and, when equal, re-points the existing row instead of inserting.

**AC #2 ÔÇö met.** The same branch moves `snapshot_id`, and `Snapshot::updateOrCreate` in `pull` moves `captured_at`. `EquipmentSnapshotImporter::selectMembersToFetch` orders on `MAX(s.captured_at)`, so rotation continues.

**AC #3 ÔÇö DEFECT.** `pull` writes changed gear with `MemberEquipmentSnapshot::updateOrCreate` keyed on `snapshot_id` + `member_id`, while `Snapshot::updateOrCreate` recycles a snapshot row per `payload_hash`. So when gear returns to a state already seen, the old hash recurs, the old snapshot row comes back, and `updateOrCreate` **overwrites the old member row instead of inserting a new one**.

Failure, all in `pull`: sweep 1 gear G ÔåÆ row1. Sweep 2 gear G2 ÔåÆ row2. Sweep 3 gear back to G ÔåÆ hash matches sweep 1 ÔåÆ row1 updated, no new row. `latestRowsFor` uses `MAX(id)`, so the member's latest row still reads G2, which is not their gear. Every later sweep sees a change, rewrites row1, and never converges. Gear swaps back and forth are ordinary in WoW. The test `writes a new row when the gear actually changes` only moves gear forwards, so it misses this.

VERDICT: defect

**scope: defect**

Reviewed the code, the commit `500d88a`, the readers of the changed tables, and the board convention.

**1. The runbook still says this job is not done.** `docs/ops-runbook.md`, section "Follow-ups (not yet done ÔÇö further headroom, in priority order)", item 1 describes this exact dedup and asks for the per-member hash column the plan rejected. The next reader is told to build it again.

**2. New reporting was added, then stopped halfway.** `PullBlizzardEquipment::handle` now prints the `unchanged` count. `SyncBlizzardSnapshotJob::handle` builds the flat key list for the sync dashboard and leaves `unchanged` out. That screen shows `equipment_matched` with no sign that no row was written. The card asked for no reporting at all, so this grew and then did not finish.

**3. It writes to a shared row the card did not name.** `EquipmentSnapshotImporter::pull` now calls `Snapshot::updateOrCreate`, so an old snapshot row gets its `captured_at` and `member_count` overwritten. `RosterController::equipmentAnalysisFor` picks the latest snapshot by that column, and `PruneSnapshots::deletableQuery` ages rows off it. The plan explains why, so it is not hidden ÔÇö but it is wider than "do not write a new row".

Nothing crossed into 0002 or 0004: no deletes, no digest changes.

VERDICT: defect

**breakage: defect**

I read the importer, the model, the migration, the pruner and the two readers.

**1. The move can hit the unique index.**
`EquipmentSnapshotImporter::pull()`, unchanged branch, does `$previous->update(['snapshot_id' => $snapshot->id])`. The table has `unique(['snapshot_id','member_id'])` (`create_member_equipment_snapshots_table`). Nothing checks the target snapshot is free.

Failure: member A wears X, then Y, then X again (a trinket swap and swap back). A now holds a row at the old snapshot S1 (X) and a newer row at S3 (X). When the batch payload repeats, `Snapshot::updateOrCreate` hands back S1, A counts as unchanged, and the move points the S3 row at S1 where a row for A already sits. Integrity violation inside `DB::transaction` kills the whole sweep. No test builds a revert.

**2. Two rules for "latest row".**
`latestRowsFor` and `BisComparisonService::readingFromBlizzardEquipment` both pick highest `id`. `selectMembersToFetch` and `PruneSnapshots::protectedRowIds` pick highest `captured_at`. Rows now move and old rows get rewritten in place, so those two orders disagree. BiS then compares against gear the player took off. Silent.

VERDICT: defect

