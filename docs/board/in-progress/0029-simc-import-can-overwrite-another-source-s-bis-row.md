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
- [ ] #1 WHEN `simc:pull` imports a spec that already has a row from another source, THE APP SHALL keep that row unchanged and write the SimC list to its own `simc` row. proves: `it leaves another source's row alone when importing SimC`
<!-- AC:END -->

## Plan
Add `'source' => 'simc'` to the match keys of the `updateOrCreate()` call in
`app/Services/Simc/SimcProfileLoader.php`. Test in `tests/Feature/SimcProfileLoaderTest.php`.

## Comments

**2026-09-29** Raised by the unattended build of 0025. Found while reading the callers of the BiS
profiles table.
