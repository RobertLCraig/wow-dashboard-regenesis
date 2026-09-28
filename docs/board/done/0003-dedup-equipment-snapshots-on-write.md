# Dedup equipment snapshots on write

## Why
`member_equipment_snapshots` reached 1.38 GB by storing a fresh 43 KB gear blob per member per
pull, and gear changes rarely. It was the single largest contributor to the 8 July outage.

The existing `snapshots.payload_hash` dedup does not help here: it hashes the whole batch, and the
batches rotate through the 100 stalest members per run, so the hash almost never matches. The dedup
exists at the wrong granularity rather than being absent, which is why the table grew despite it.

## Links

**Relates to**
- `0002` - warns in the weekly digest before the cap is reached again; this card slows the growth,
  that one measures it.
- `0004` - hands back the space the table has already taken; this card only stops it taking more.

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


**2026-08-29** The reviewer returned this card and its finding is the last review entry at the bottom of ## Direction. The loop moved it from todo/ to human-review/ because it has bounced 1 time between todo and ai-review, all 3 criteria ticked. THE BUILDER COULD NOT ACT ON THAT FINDING. A reviewer never unticks a criterion - it is forbidden from editing acceptance at all - so the card came back with 3 of 3 criteria still ticked, every session found nothing open to do, and the loop promoted it again on the boxes. Untick what the reviewer disproved and move it back to todo/, or say here why the finding is wrong.

**2026-09-28** Manager pass: reopened #3 because the 2026-08-29 acceptance and breakage findings hold
on `main`; `EquipmentSnapshotImporter.php` has changed only in the Pint reformat since `500d88a`. When
a member's gear returns to a state already seen, `Snapshot::updateOrCreate` hands back the old
snapshot row. The changed branch then overwrites the old member row through
`MemberEquipmentSnapshot::updateOrCreate`, and the unchanged branch's `$previous->update(['snapshot_id'
=> ...])` can hit `unique(['snapshot_id','member_id'])` and abort the whole sweep. The existing test
only moves gear forwards. A test that swaps an item and swaps it back is what #3 needs.

**2026-09-28** RESULT: done
TESTS: +2 new, all green
TOUCHED: app/Services/Blizzard/EquipmentSnapshotImporter.php
tests/Feature/EquipmentSnapshotImporterTest.php
docs/board/in-progress/0003-dedup-equipment-snapshots-on-write.md
docs/board/todo/0018-runbook-still-lists-equipment-dedup-as-not-done.md
docs/board/todo/0019-sync-dashboard-omits-the-unchanged-equipment-count.md
OUT-OF-SCOPE: 0018, 0019

Fixed both 2026-08-29 defects at the one place they share. When the batch hash recurs and hands back
an old snapshot, `pull` now looks up the rows already on that snapshot for this batch's members. A
member's row there that is not their newest row is an older copy of the gear they have on now (same
hash, same payload), so it is deleted before the member is handled. The changed branch then inserts
a fresh row, which becomes the newest by `id`. The unchanged branch can then move the newest row onto
the snapshot without hitting `unique(snapshot_id, member_id)`.

Two new tests, both watched red first for the reviewers' reasons:
- `it writes a new row when the gear is swapped back to a state already seen` - failed "295 is
  identical to 282": the newest row read gear the member had taken off.
- `it survives a recycled snapshot that already holds a row for an unchanged member` - failed with
  `UniqueConstraintViolationException` on the move.

Assumed: losing that one older duplicate row is fine. Its content equals the row that replaces it, and
HANDOVER records that snapshot history is expendable and latest state is not. Side effect, not tested
separately: every member's newest row by `id` now also hangs off the newest snapshot, so the two
"latest" rules the breakage review named (`MAX(id)` and `MAX(captured_at)`) agree again for members
in a sweep. The scope review's other points became cards 0018 (runbook) and 0019 (sync dashboard
count). Its point 3, the `captured_at` rewrite on a recycled snapshot, is what `## Plan` already
chose, so no card. Full suite 752 green. `pint --test` fails only on
`app/Services/Raiderio/RaiderioSnapshotImporter.php`, which is card 0014's known file.

### 2026-09-28 review (v20260928223932-1afd)

**suite**

`vendor\bin\pest.bat` exited 0 after 87s, run by this job rather than reported by the card.

**acceptance: sound**

I tried to break all three criteria. I could not.

**AC #1: met.** `EquipmentSnapshotImporter::pull` compares the new ilvls and `pieces` with the member's newest row from `latestRowsFor`. When they match, it moves that row with `$previous->update(['snapshot_id' => ...])`. It inserts nothing.

**AC #2: met.** That same branch moves `snapshot_id`. `Snapshot::updateOrCreate` in `pull` moves `captured_at`. So `selectMembersToFetch` keeps rotating. The test `keeps rotating through the roster when it skips a write` covers this.

**AC #3: met.** Changed gear goes to `MemberEquipmentSnapshot::updateOrCreate`, keyed on the current snapshot. The 2026-08-29 swap-back defect is fixed now. A snapshot comes back only when the batch hash repeats. The hash covers every member's full payload. So any row already on that snapshot holds the gear the member wears now. `pull` deletes that row when it is not the newest one. Two things follow:
- A real change can never land on a snapshot that already has a row for that member. It always inserts a new row.
- The unchanged move can no longer hit `unique(snapshot_id, member_id)`.

Two tests cover the swap-back case: `writes a new row when the gear is swapped back to a state already seen` and `survives a recycled snapshot that already holds a row for an unchanged member`. The suite is green.

VERDICT: sound

**scope: sound**

I checked the scope of this card. I found no scope defect.

**What I looked at:** `a70972e`, which changes `EquipmentSnapshotImporter::pull`, adds two tests and raises cards 0018 and 0019. Almost all of the 227-file diff belongs to other cards or to the Pint reformat (`3e7ac11`), not to 0003.

- **Deletes a row.** `EquipmentSnapshotImporter::pull` now deletes a member's old row when a reused snapshot still holds it. "Not this card" fences off reclaiming space (0004). This delete does not reclaim space. It removes one duplicate row so the sweep does not overwrite the wrong row or crash. No history is lost: the plan already moves that snapshot's `captured_at` to now, so the row's old date was gone anyway. It is in the comment and the commit message, so it is not a quiet change.
- **Unfinished work from the last scope review is now on cards.** The runbook still lists this dedup as not done (card 0018). The sync dashboard does not show the `unchanged` count (card 0019). Neither is left hidden.
- **The `captured_at` rewrite.** This is the choice `## Plan` made on purpose. It is not scope growth.
- **Nothing goes into 0002 or 0004.** No digest change and no pruning change.

I disproved no criterion.

VERDICT: sound

**breakage: sound**

**Breakage review of card 0003: I tried to break it and could not.**

I read `EquipmentSnapshotImporter::pull` and `EquipmentSnapshotImporter::latestRowsFor`. I checked them against the readers the last breakage review named.

- **The unique-index crash is fixed.** `pull` now deletes an older row that the member has on the reused snapshot. It does this before the move. So `$previous->update(['snapshot_id' => ...])` cannot hit `unique(snapshot_id, member_id)`.
- **The in-place overwrite is fixed.** After that delete, the changed branch's `updateOrCreate` makes a new row. That row gets the highest `id`, so `latestRowsFor` reads the gear the member has on now.
- **The deleted row is safe to lose.** The batch hash covers every member's payload. The same hash means the old row holds the same gear as the new one.
- **The two "latest" rules agree again.** `latestRowsFor` and `BisComparisonService::readingFromBlizzardEquipment` pick the highest `id`. `selectMembersToFetch` and `PruneSnapshots::protectedRowIds` pick the newest `captured_at`. After each sweep, a member's highest-`id` row sits on the newest snapshot, so both rules pick the same row. The pruner protects that row.
- **No comment is now false.** The comment in `pull` and the docblock on `latestRowsFor` still match what the code does.

This finding disproves no criterion. I write no `UNMET:` lines.

VERDICT: sound

