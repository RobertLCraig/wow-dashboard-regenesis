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
- [ ] #1 WHEN the Blizzard sync job runs the equipment sweep, THE JOB SHALL record the sweep's
      `unchanged` count beside `equipment_matched`. proves: `it records the unchanged equipment count on the sync run`
<!-- AC:END -->

## Tasks
- [ ] Add `equipment_unchanged` next to `equipment_matched` in `app/Jobs/SyncBlizzardSnapshotJob.php`

## Comments
