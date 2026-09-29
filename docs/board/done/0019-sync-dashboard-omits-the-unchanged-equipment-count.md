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

### 2026-09-29 review (v20260929011314-97f6)

**suite**

`vendor\bin\pest.bat` exited 0 after 120s, run by this job rather than reported by the card.

**acceptance: sound**

I checked the one criterion, #1. The code does what it says.

- **The count comes from the importer.** `EquipmentSnapshotImporter::pull` counts each member whose gear did not change. It returns that number under the name `unchanged`.
- **The job saves the count.** `SyncBlizzardSnapshotJob::handle` now writes `'equipment_unchanged' => $equipmentResult['unchanged'] ?? null` into the sync run's summary. It is on the line directly after `equipment_matched`, as the card asks.
- **A test covers it.** The test `it records the unchanged equipment count on the sync run` is in `tests/Feature/SyncBlizzardSnapshotJobTest.php`. It checks that the summary has the key `equipment_unchanged` with the value 1. Before the fix, it failed because the key was not there.
- **The suite is green.** The script ran the full suite before I started, and it passed.

One gap is still open, but it does not break #1. Nobody has opened the sync dashboard in a browser to see the new number. The card says that the dashboard is out of scope.

I tried to break #1 and could not.

VERDICT: sound

**scope: sound**

**Scope review of card 0019: the unchanged count on the sync dashboard**

I tried to find work outside the card's limits. I did not find any.

- **The job.** `SyncBlizzardSnapshotJob::handle` gets one new key, `equipment_unchanged`, beside `equipment_matched`. That is the card's only task.
- **The test.** `tests/Feature/SyncBlizzardSnapshotJobTest.php` adds one test, `it records the unchanged equipment count on the sync run`. It only reads the stored summary. It does not change the importer.
- **"Not this card".** The fence excludes the importer and the dashboard view. The builder changed neither `EquipmentSnapshotImporter` nor any sync-dashboard Blade file.
- **The rest of the diff.** Most of the diff belongs to other cards: 0005, 0006, 0014, 0015, 0021 to 0024, the README and the HANDOVER. None of it is in this card's own list of changed files, so none of it counts against 0019.
- **Half done.** Nobody has checked the dashboard in a browser. The builder says so. But the card fences off how the dashboard shows its keys, so that check is not owed under this card's criterion.

My findings disprove no criterion.

VERDICT: sound

**breakage: sound**

I tried to break card 0019. It did not break.

**What I checked**

- **Who writes the count.** `EquipmentSnapshotImporter::pull` gives back `unchanged`. `SyncBlizzardSnapshotJob::handle` now copies it to `equipment_unchanged`, right beside `equipment_matched`. `PullBlizzardEquipment` still reads the same key, so nothing it uses changed.
- **Who reads the key.** Nothing reads `equipment_unchanged` by name. The sync view `admin/sync/index.blade.php` shows every key in the summary. A key with no label uses its own name as the label. So the new key shows with no view change.
- **When the sweep does not run.** The value is `null`, like the other equipment keys. The view skips null values. That matches how the other keys work, so there is no new case to go wrong.
- **Comments.** No comment or docblock is now false.
- **Browser check.** Nobody opened the page in a browser. Reading the view shows it would render the key, so this does not disprove the criterion.

No criterion is disproved.

VERDICT: sound

