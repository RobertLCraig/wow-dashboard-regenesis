---
needs: 0027
---
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

**Blocked by**
- `0027` - Wowhead's terms forbid the scrape, so criteria #3, #4 and #7 wait on Rob's call there.

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
- [x] #1 WHEN the source migration runs, THE APP SHALL keep every existing `bis_profiles` row, tagged `simc` for SimC imports and `manual` for the healer stubs. proves: `it keeps every existing BiS row through the source migration`
- [x] #2 WHEN a DPS character page is opened with no source chosen, THE APP SHALL compare against SimC exactly as it does today. proves: `it defaults a DPS character to the SimC comparison`
- [ ] #3 WHEN the Wowhead ingest parses a saved Wowhead healer BiS page, THE APP SHALL store one profile for that spec with an item id in every slot the page names. proves: `it parses a Wowhead BiS page into per-slot item ids`
- [ ] #4 IF a Wowhead page will not parse, THEN THE APP SHALL exit non-zero naming the spec and leave that spec's stored profile untouched. proves: `it leaves the stored profile alone when a Wowhead page will not parse`
- [x] #5 WHEN a healer character page is opened with no source chosen, THE APP SHALL show the first source that has gear for that spec and name the source and its capture date above the table. proves: `it defaults a healer to the first source that has gear`
- [x] #6 WHEN a source has no profile for the character's spec, THE APP SHALL show that source's tab disabled rather than an empty table. proves: `it disables the tab of a source with no profile for the spec`
- [ ] #7 WHEN `php artisan bis:pull --source=wowhead` runs against the live Wowhead site from a local checkout, THE APP SHALL fill every healer spec (check: open one healer's character page locally and every slot the page lists shows a BiS item). proves: manual
<!-- AC:END -->

## Tasks
- [x] Read Wowhead's `robots.txt` and terms for the per-spec BiS guide pages. **If either forbids this fetch, stop and raise a `human-review/` card rather than building the scraper**
- [x] Migration: add `source` to `bis_profiles` (default `simc`), swap the unique index to include it, and backfill the healer stubs to `manual`
- [x] `SeedHealerBisProfiles`: write `source = 'manual'`
- [x] `BisComparisonService`: `compareForMember()` takes an optional source, falling through a fixed order when none is given; add `availableSourcesFor()`
- [ ] A `bis:pull --source=wowhead` command: fetch each healer spec's guide page, lift the item ids, upsert on class, spec, hero talent and source. Parse from a saved fixture in the tests, never the live site
- [x] `resources/views/dashboard/character/_bis-comparison.blade.php`: the source tab strip, driven by `?bis_source=`
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

## Comments

**2026-09-29** RESULT: partial
TESTS: +4 new, all green
TOUCHED: app/Console/Commands/SeedHealerBisProfiles.php
TOUCHED: app/Http/Controllers/Dashboard/CharacterController.php
TOUCHED: app/Models/BisProfile.php
TOUCHED: app/Services/Bis/BisComparisonService.php
TOUCHED: database/migrations/2026_09_29_120000_add_source_to_bis_profiles_table.php
TOUCHED: resources/views/dashboard/character/_bis-comparison.blade.php
TOUCHED: resources/views/dashboard/character/show.blade.php
TOUCHED: tests/Feature/BisComparisonServiceTest.php
TOUCHED: tests/Feature/CharacterDetailTest.php
TOUCHED: tests/Feature/SeedHealerBisProfilesTest.php
TOUCHED: docs/board/in-progress/0025-healer-bis-from-wowhead-through-a-per-source-bis-table.md
TOUCHED: docs/board/todo/0027-wowhead-terms-forbid-the-bis-scrape.md
TOUCHED: docs/board/todo/0028-roster-bis-count-mixes-sources.md
TOUCHED: docs/board/todo/0029-simc-import-can-overwrite-another-source-s-bis-row.md
TOUCHED: docs/HANDOVER.md
OUT-OF-SCOPE: 0027, 0028, 0029

**Stopped at the terms check.** Wowhead's `robots.txt` allows `/guides/` for a normal user agent.
Its terms (`corp.fanbyte.com/legal/terms`) forbid downloading content with any tool other than a
browser, and forbid datamining it. So no scraper, no saved Wowhead fixture, and #3, #4 and #7 stay
open. The task said to raise a `human-review/` card; the unattended rules allow new cards only in
`todo/`, so 0027 sits there with `not_for_the_loop:` and a `## What I need from you` section.
`needs: 0027` on this card holds the loop off the rest.

**Built: plan steps 1 to 3.** The migration adds `source` (default `simc`), swaps the unique index
to include it, and tags rows whose `source_path` ends `.json` as `manual` (the seeder stamps its JSON
path; SimC rows point at `.simc` files). `bis:seed-healers` writes and matches on `source = 'manual'`.
`compareForMember($member, $source = null)`: with no source it takes the first source in
`BisProfile::SOURCES` order (`simc`, `wowhead`, `manual`) whose profile has gear, else the first with
any row, so a healer's stub still renders its consumables. `availableSourcesFor()` feeds the tab
strip. The controller only trusts a `?bis_source=` that is in that list; anything else falls back to
the default rather than an empty table. The header names the source and its `captured_at` date.

**Tests.** Each was watched failing first for its criterion's reason: #1 on the healer row staying
`simc`; #2 on the page showing the manual row; #5 on the page showing Wowhead's empty row over
manual's full one; #6 on the missing disabled tab. For #5 the first draft passed its first half by
luck, because the old gear tie-break follows the new index's `source` order; I reordered it so the
half the old code gets wrong runs first. `CharacterDetailTest`'s throwing stub needed the new
`compareForMember()` signature to load at all; its assertions are unchanged. One assertion added to
`SeedHealerBisProfilesTest` for `source = 'manual'`.

**Owed.** A browser check of the tab strip: Herd serves `C:\Dev\Regenesis`, not this worktree. The
migration is untested on MySQL (the suite is SQLite); it uses only named-index drops and a plain
`UPDATE`.
