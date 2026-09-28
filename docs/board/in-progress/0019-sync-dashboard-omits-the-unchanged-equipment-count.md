# Sync dashboard omits the unchanged equipment count

## Why
Since card 0003, the equipment sweep skips writing a row when a member's gear has not changed, and
returns how many it skipped as `unchanged`. The artisan command `PullBlizzardEquipment` prints that
number. The queued `SyncBlizzardSnapshotJob::handle` builds the key list for the sync dashboard and
leaves it out, so that screen shows `equipment_matched` with no sign that most of those members got
no new row. An officer reading it cannot tell a sweep that wrote 100 rows from one that wrote none.

It happened because card 0003 added the count to one caller and not the other.

## Links

**Relates to**
- `0003` - added the `unchanged` count to the importer's result.

## Not this card
Any change to the importer itself, or to how the dashboard renders its keys.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN the Blizzard sync job runs the equipment sweep, THE JOB SHALL record the sweep's
      `unchanged` count beside `equipment_matched`. proves: `it records the unchanged equipment count on the sync run`
<!-- AC:END -->

## Tasks
- [x] Add `equipment_unchanged` next to `equipment_matched` in `app/Jobs/SyncBlizzardSnapshotJob.php`

## Comments

**2026-09-29** RESULT: done
TESTS: +1 new, all green
TOUCHED: app/Jobs/SyncBlizzardSnapshotJob.php
tests/Feature/SyncBlizzardSnapshotJobTest.php
docs/board/in-progress/0019-sync-dashboard-omits-the-unchanged-equipment-count.md
OUT-OF-SCOPE: none

The job now puts `equipment_unchanged` beside `equipment_matched` in the sync summary. The new test
runs the whole job twice against faked Blizzard responses with the same gear, then reads the stored
summary. It was watched failing first on "array has the key 'equipment_unchanged'", with the run
status DONE, so the fault it caught was the missing key and not a broken fixture. Full suite 761
green, `pint --test` passed. The dashboard renders summary keys generically, so the new key should
show with no view change, but that is unchecked: a browser check against the sync dashboard is
still owed, and it cannot be run from this worktree.
