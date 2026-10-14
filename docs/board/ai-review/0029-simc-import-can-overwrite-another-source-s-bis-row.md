# The SimC import can overwrite another source's BiS row

## Why
`SimcProfileLoader` saves each SimC profile with `updateOrCreate()` keyed on class, spec and hero
talent only. Since card 0025, `bis_profiles` holds one row per source for the same three keys. If a
`wowhead` or `manual` row already exists for a spec SimC also ships, the next `simc:pull` finds that
row and overwrites it with SimC data. The row still says `wowhead` or `manual`, so the page shows
SimC's list under another source's name, and the other source's list is lost.

Nobody sees it yet. Today SimC ships no healers and the other sources hold only healers.

## Links

**Relates to**
- `0025` - added the `source` column and made the seeder key on it. The SimC loader was not in
  its plan.

## Not this card
`bis:seed-healers`, which 0025 already keys on `source = 'manual'`.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN `simc:pull` imports a spec that already has a row from another source, THE APP SHALL keep that row unchanged and write the SimC list to its own `simc` row. proves: `it leaves another source's row alone when importing SimC`
<!-- AC:END -->

## Plan
Add `'source' => 'simc'` to the match keys of the `updateOrCreate()` call in
`app/Services/Simc/SimcProfileLoader.php`. Test in `tests/Feature/SimcProfileLoaderTest.php`.

## Comments

**2026-09-29** Raised by the unattended build of 0025. Found while reading the callers of the BiS
profiles table.

**2026-10-14** RESULT: done
TESTS: +1 new, all green (802 passed)
TOUCHED: app/Services/Simc/SimcProfileLoader.php
TOUCHED: tests/Feature/SimcProfileLoaderTest.php
OUT-OF-SCOPE: none

Added 'source' => 'simc' to the updateOrCreate() match keys, as the Plan says. The new test seeds a wowhead row for death_knight/frost/rider (a spec the SimC fixtures ship), runs the loader, and checks the wowhead row is unchanged and a separate simc row holds the SimC list. Watched it fail first: the wowhead row's profile_name was overwritten with 'MID1_Death_Knight_Frost_Rider'. Pint clean. No browser check needed: no page changed.
