# The roster's BiS issue count mixes every source's list for a spec

## Why
The roster's BiS column counts missing enchants and gems. `RosterController::bisIssuesByMember()`
groups every `bis_profiles` row by class and spec, then lets `pickBestProfileFromGear()` choose by
gear overlap. Since card 0025, one spec can have a row per source (`simc`, `wowhead`, `manual`). The
roster can then count against a different source's list than the one the character page shows.

Nobody sees it yet. Today each spec has rows from one source only: SimC for DPS and tanks, the
manual stubs for healers. It shows up when a second source fills a spec that already has rows.

## Links

**Relates to**
- `0025` - added the `source` column and the default order the character page uses.

## Not this card
The character page, which already picks by source. Any new source.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a spec has profiles from more than one source, THE roster's BiS issue count SHALL use the same source the character page picks with no source chosen. proves: `it counts roster BiS issues against the default source`
<!-- AC:END -->

## Plan
`BisComparisonService::candidatesForSource()` is private and already does the choosing. Make it
callable from the roster, and pass each spec's rows through it before `pickBestProfileFromGear()`.
Test in `tests/Feature/RosterTest.php`: a `simc` and a `manual` row for one DPS spec, where the
player's gear matches the manual row, and the count must follow the SimC row.

## Comments

**2026-09-29** Raised by the unattended build of 0025. Found while reading the callers of the BiS
profiles table.

**2026-10-14** RESULT: done
TESTS: +1 new, all green (801 passed)
TOUCHED: app/Services/Bis/BisComparisonService.php
         app/Http/Controllers/Dashboard/RosterController.php
         tests/Feature/RosterTest.php
OUT-OF-SCOPE: none

`BisComparisonService::candidatesForSource()` is now public. `RosterController::bisIssuesByMember()` passes each spec's rows through it with no source chosen (`null`), before `pickBestProfileFromGear()`. This is the same call the character page makes, so both pick the same source.

Test `it counts roster BiS issues against the default source`: a frost priest with a `simc` row and a `manual` row. The manual row overlaps the player's gear more and asks for no enchant. The SimC row asks for a head enchant. Before the fix it was red for the right reason: the roster picked the manual row and showed 0 BiS issues. After the fix it counts the 1 missing SimC enchant.

No browser check done: Herd serves C:\Dev\Regenesis, not this worktree. The roster BiS column still needs one look after the merge.
