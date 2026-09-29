# Healer BiS from Wowhead, through a per-source BiS table

## Why
A healer who opens their character page sees an empty best-in-slot column. Every slot shows what
they are wearing and nothing about what they should be wearing, so the feature looks broken to
exactly the players it says nothing to. That is roughly seven specs, every healer in the guild.

It happened because the one source this project reads, SimulationCraft's `profiles/MID1` directory,
ships DPS and tank profiles only. `bis:seed-healers` was added on 2026-04-30 to seed stub rows so the
widget renders consumables instead of a blank panel, but its data file,
`database/data/healer-bis-profiles.json`, carries empty gear by design. `bis_profiles` also has one
row per class, spec and hero talent, so there is nowhere to put a second source's list even if one
were fetched.

## Links

**Relates to**
- `0009` - the decision this card carries out. Option 3 was chosen on 2026-08-15: build the
  multi-source ingest first and fill the healers from it, not hand-curate seven specs that go stale
  every tier.

## Not this card
Not Method, QuestionablyEpic or Icy Veins: the plan lists them as later sources, each its own card.
Not stale-data warnings and not a per-officer default-source preference, which are open questions 1
and 2 in the plan. Not hand-curating healer lists into `healer-bis-profiles.json`, which is option 1
and was not chosen. Not a deploy and not running the new command against the live database: both
are a person's step after review.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN the source migration runs, THE APP SHALL keep every existing `bis_profiles` row, tagged `simc` for SimC imports and `manual` for the healer stubs. proves: `it keeps every existing BiS row through the source migration`
- [ ] #2 WHEN a DPS character page is opened with no source chosen, THE APP SHALL compare against SimC exactly as it does today. proves: `it defaults a DPS character to the SimC comparison`
- [ ] #3 WHEN the Wowhead ingest parses a saved Wowhead healer BiS page, THE APP SHALL store one profile for that spec with an item id in every slot the page names. proves: `it parses a Wowhead BiS page into per-slot item ids`
- [ ] #4 IF a Wowhead page will not parse, THEN THE APP SHALL exit non-zero naming the spec and leave that spec's stored profile untouched. proves: `it leaves the stored profile alone when a Wowhead page will not parse`
- [ ] #5 WHEN a healer character page is opened with no source chosen, THE APP SHALL show the first source that has gear for that spec and name the source and its capture date above the table. proves: `it defaults a healer to the first source that has gear`
- [ ] #6 WHEN a source has no profile for the character's spec, THE APP SHALL show that source's tab disabled rather than an empty table. proves: `it disables the tab of a source with no profile for the spec`
- [ ] #7 WHEN `php artisan bis:pull --source=wowhead` runs against the live Wowhead site from a local checkout, THE APP SHALL fill every healer spec (check: open one healer's character page locally and every slot the page lists shows a BiS item). proves: manual
<!-- AC:END -->

## Tasks
- [ ] Read Wowhead's `robots.txt` and terms for the per-spec BiS guide pages. **If either forbids this fetch, stop and raise a `human-review/` card rather than building the scraper**
- [ ] Migration: add `source` to `bis_profiles` (default `simc`), swap the unique index to include it, and backfill the healer stubs to `manual`
- [ ] `SeedHealerBisProfiles`: write `source = 'manual'`
- [ ] `BisComparisonService`: `compareForMember()` takes an optional source, falling through a fixed order when none is given; add `availableSourcesFor()`
- [ ] A `bis:pull --source=wowhead` command: fetch each healer spec's guide page, lift the item ids, upsert on class, spec, hero talent and source. Parse from a saved fixture in the tests, never the live site
- [ ] `resources/views/dashboard/character/_bis-comparison.blade.php`: the source tab strip, driven by `?bis_source=`
- [ ] Pest tests named in Acceptance, in `tests/Feature/BisComparisonServiceTest.php` and a new test file for the command

## Plan
Stand in `C:\Dev\Regenesis`, on `main`. Run the suite first with `php artisan test` from the
PowerShell tool (PHP comes from Laravel Herd and is not on the Git Bash PATH).

The design is already written: [docs/planning/multi-source-bis-tabs.md](../../planning/multi-source-bis-tabs.md).
Follow its "Migration path" steps 1 to 4 in order. Each step leaves the app working, so the card can
stop between any two of them. The plan's schema block, service signatures and tab design are the
spec; this card only settles what it left open:

- **Default source order:** `simc`, then `wowhead`, then `manual`. That keeps DPS on SimC (#2) and
  puts healers on Wowhead (#5), because SimC has no healer rows.
- **Hero talents:** if Wowhead gives one list per spec, store it with `hero_talent = null` and let
  `pickBestProfileFromGear()` take the only candidate. This is the plan's open question 3.

Wowhead's guide URLs follow `/guides/{class}-{spec}-bis-pve`, per the plan. Save one real page per
spec layout as a test fixture before writing the parser, and check the saved page really carries the
item links the plan expects.
