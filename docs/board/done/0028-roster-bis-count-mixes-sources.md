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

### 2026-10-14 review (v20261014212512-d703)

**suite**

`vendor\bin\pest.bat` exited 0 after 110s, run by this job rather than reported by the card.

**acceptance: sound**

I tried to break criterion #1 and could not.

**#1: the roster's BiS count uses the same source as the character page.** This holds.

- **The character page:** `BisComparisonService::compareForMember()` gets every row for the member's class and spec. It passes them through `candidatesForSource($candidates, $source)`. With no source chosen, `$source` is `null`. Then it calls `pickBestProfileFromGear()`.
- **The roster:** `RosterController::bisIssuesByMember()` groups every row by `class|spec`. For each member it takes that spec's group. It passes the group through `$service->candidatesForSource(..., null)` before it calls `pickBestProfileFromGear()`.
- **Same rows in, same rule:** Both callers give the method the same set of rows. That set is every row for one class and spec. Both use the same public method with `null`. That method takes the first source in `BisProfile::SOURCES` order that has gear. So both pages pick the same source.
- **The test:** `it counts roster BiS issues against the default source` is in `tests/Feature/RosterTest.php`. It makes a `simc` row and a `manual` row for frost priest. The manual row matches more of the player's gear. The test expects 1 issue, which is the SimC head enchant.
- **The suite:** It passed (exit 0).

**Not checked:** nobody has looked at the roster in a browser. The card says so, and that check is not one of the criteria.

VERDICT: sound

**scope: sound**

The card's own commit is `88d73d2`. It changes only the three files that the card's TOUCHED list names.

- **`BisComparisonService::candidatesForSource()`:** one change. The method is now public, as the Plan asks. Its body did not change.
- **`RosterController::bisIssuesByMember()`:** one changed line. It sends each spec's rows through `candidatesForSource(..., null)` before `pickBestProfileFromGear()`. This is the exact call the Plan names. Nothing else in the method changed.
- **`tests/Feature/RosterTest.php`:** one new test, with the name that the criterion gives. It uses a `simc` row and a `manual` row for one spec. The player's gear matches the manual row better, and the count must follow SimC. This is what the Plan asks for.
- **The fence holds.** The character page did not change, and no new source was added.
- **The large diff is from other cards.** These are the CSV column list (0026), TeamResolver events (0033), the pipeline page (0034), the digest (0031), the webhook regex (0030) and the board moves. None of them are in this card's commit.
- **Nothing is half done.** The one browser check that is still owed is not a criterion.

My findings disprove no criterion.

VERDICT: sound

**breakage: sound**

I tried to break card 0028. I could not.

- **Only one other caller.** `candidatesForSource()` in `app/Services/Bis/BisComparisonService.php` has one other caller: the character page's comparison in the same class. That call does not change. The method only changed from private to public, and its body is the same.
- **The roster gets every column.** `RosterController::bisIssuesByMember()` loads full `BisProfile` rows with `BisProfile::query()->get()`. So `source` and `parsed_data` are there for the default-source rule. Nothing is trimmed away.
- **Empty spec.** If a spec has no rows, the roster passes an empty `collect()`. With no rows, both loops in `candidatesForSource()` do nothing. `pickBestProfileFromGear()` then gets an empty list and the member is skipped, as before.
- **One source.** If a spec has rows from only one source, the method gives back those rows unchanged. So the count stays the same for every spec today.
- **Comments.** The docblock on `candidatesForSource()` says nothing about being private, so the change makes no comment false.
- **Not this card:** the roster reads the spec from Raider.IO's `active_spec_name`. The character page reads it from `resolveGearReading()`. That difference was there before this card, and the card does not cover it.

I found no criterion that fails.

VERDICT: sound

