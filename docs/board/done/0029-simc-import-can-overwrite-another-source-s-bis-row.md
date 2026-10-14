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

### 2026-10-15 review (v20261015001443-6330)

**suite**

`vendor\bin\pest.bat` exited 0 after 126s, run by this job rather than reported by the card.

**acceptance: sound**

I tried to break criterion #1. I could not break it.

**#1: SimC keeps out of other sources' rows.** This holds.

- **The code:** `SimcProfileLoader::loadFromDirectory()` in `app/Services/Simc/SimcProfileLoader.php` saves each profile with `BisProfile::updateOrCreate()`. The lookup keys are now `class`, `spec`, `hero_talent` and `source => 'simc'`. A `wowhead` or `manual` row cannot match these keys. So the import does not change that row. It creates or updates a separate `simc` row.
- **The test:** `it leaves another source's row alone when importing SimC` is in `tests/Feature/SimcProfileLoaderTest.php`. It does these steps:
  1. It adds a `wowhead` row for death_knight/frost/rider.
  2. It runs the loader on the SimC test files.
  3. It checks that the wowhead row's `source`, `profile_name`, `source_path` and `parsed_data` did not change.
  4. It checks that there is a separate `simc` row that holds the SimC head item.
- **The suite:** It passed (exit 0). The script ran it, not the builder.

My findings disprove no criterion.

VERDICT: sound

**scope: sound**

I checked what card 0029 changed against what it asked for. I found no scope problem.

- **Only this card's work is in its commit.** Commit `e4a4243` changes two files: `app/Services/Simc/SimcProfileLoader.php` and `tests/Feature/SimcProfileLoaderTest.php`. The card's TOUCHED list names the same two files.
- **The code change is the one line the Plan names.** `SimcProfileLoader::loadFromDirectory()` adds `'source' => 'simc'` to the match keys of `updateOrCreate()`. Nothing else in that method changed.
- **The test stays in scope.** The new test is `it leaves another source's row alone when importing SimC`. It does three things:
  - It makes a `wowhead` row for a spec that the SimC test files also have.
  - It checks that the `wowhead` row does not change.
  - It checks that a separate `simc` row holds the SimC list.
- **The fence holds.** `bis:seed-healers` (`SeedHealerBisProfiles`) did not change. It already matches on `source`.
- **Nothing is half done.** No other code in `app/` writes or deletes `bis_profiles` rows without `source`.
- **The rest of the diff comes from other cards.** That is the webhook, roster, pipeline, digest, TeamResolver and board work. None of it is in this card's commit.

My findings disprove no criterion.

VERDICT: sound

**breakage: sound**

I tried to break the card 0029 change. I could not find a break.

- **The change:** `SimcProfileLoader::loadFromDirectory()` now finds its row by class, spec, hero talent and `source = 'simc'`. A `wowhead` or `manual` row cannot match, so the loader cannot overwrite it.
- **Old SimC rows:** the migration `add_source_to_bis_profiles_table` sets `source` to `'simc'` by default. It sets `manual` only for `.json` paths. So the SimC rows already in the table still match. The next `simc:pull` updates them and makes no copies.
- **The unique key:** the table is unique on class, spec, hero talent and source. That is the same key the loader now uses, so two writes cannot clash.
- **Other writers:** the only other writer is `SeedHealerBisProfiles`. It already keys on `manual`, and the card leaves it out of scope.
- **Readers:** `candidatesForSource()` already picks one source per spec. A SimC row next to a `wowhead` row is the shape card 0025 was built for.
- **Comments:** the class docblock does not mention the match key, so no comment is now wrong.

What you do now: nothing. The card can go to `done/`.

VERDICT: sound

