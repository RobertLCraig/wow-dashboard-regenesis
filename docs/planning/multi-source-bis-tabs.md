# Multi-source BiS comparison (tabs on the character page)

**Status**: scoped, not built. Captured here so we can pick up cleanly when ready.

## Why this exists

The BiS comparison widget on the character page currently has one
canonical reference per (class, spec, hero_talent): a row in
`bis_profiles`, populated by `simc:pull --fetch` for DPS / tank specs
and by `bis:seed-healers` for healers. That works, but it has two
real limits:

- **Source-of-truth disagreement**. Different communities recommend
  different gear. SimC's "BiS" is sim-optimal at item-level cap;
  Wowhead's leans more practically obtainable; Method writes their
  recommendations around the world-first roster's actual loadouts;
  QuestionablyEpic optimises healers by stat weights; Icy Veins
  hedges between them. Officers and players legitimately disagree on
  whose BiS to follow, and the dashboard currently picks one for them.
- **Healer coverage gap**. SimC doesn't ship healer profiles at all
  (see [healer-bis-investigation memory](../../C:\Users\r\.claude\projects\c--Dev-Regenesis\memory\project_healer_bis_investigation.md)).
  The current healer rows are stub shells with consumables only.
  Wowhead / QE / Method all carry healer recommendations; tabbing
  between them lets each healer pick the source that matches their
  build.

The proposed shape: tabs along the top of the BiS panel (e.g.
`SimC`  `Wowhead`  `Method`  `QE`  `Manual`), with each tab
disabled when there's no profile for that (class, spec, source)
combo. Clicking a tab swaps the BiS column; the player's actual gear
panel below is unchanged.

## Goals

1. Make the BiS comparison answer "which source's BiS am I looking
   at?" explicitly. No silent picking.
2. Cover healers properly via per-source data — SimC stays empty for
   them, but Wowhead/QE/Method can fill the gap.
3. Don't regress the today-working flow: officers viewing a DPS
   character page must still see the SimC comparison by default,
   without clicking anything.
4. Keep total `bis_profiles` storage bounded — Hostinger's 3 GB DB
   cap is the hard ceiling (see [social snapshots constraints memory](../../C:\Users\r\.claude\projects\c--Dev-Regenesis\memory\project_social_snapshots_constraints.md)).
   N sources × ~50 specs × hero-talent variants is fine; per-row size
   is what matters.

## Non-goals

- Live re-running the QE / SimC simulators in-app. We ingest static
  data per source, refreshed on a schedule.
- Showing every source for every spec. If Wowhead doesn't have a
  page for Augmentation Evoker yet, that tab simply doesn't render.
- Global "best of all sources" merging. Officers want to know whose
  recommendation they're seeing; merging defeats the purpose.

## Schema changes

`bis_profiles` today is keyed on `(class, spec, hero_talent)` with
`profile_name` and `parsed_data` (which carries `gear`, `consumables`,
`gear_ilvl`).

Add a `source` column with a small enum:

```php
$table->string('source', 16)->default('simc'); // simc, wowhead, method, qe, icy_veins, manual
$table->dropUnique(['class', 'spec', 'hero_talent']);
$table->unique(['class', 'spec', 'hero_talent', 'source']);
$table->index('source');
```

Migration is straightforward: backfill existing rows with
`source = 'simc'` for SimC-imported rows, `source = 'manual'` for
the healer stubs (or rename to `qe` once we wire QE properly).

`source_path` column already exists - keep it for traceability
(filesystem path for SimC, URL for Wowhead, etc.).

## Service changes

`BisComparisonService::compareForMember(Member $member, ?string $source = null)`:
- When `$source` is null, return the existing best-effort behaviour
  (preferred order: simc > qe > wowhead > method > manual, picking
  the first that has a row for this class+spec). Today's default.
- When `$source` is given, restrict the candidate query to that
  source. If no row exists, return null (the tab is disabled).

A new `availableSourcesFor(Member $member): array<string>` so the
view knows which tabs to enable.

`pickBestProfileFromGear` already takes a candidates collection so
the source filter is just a `where('source', $source)` upstream of it.

## UI / tab design

Character page (`resources/views/dashboard/character/_bis-comparison.blade.php`):

- Tab strip above the BiS table, each tab is one source label.
- Disabled tabs are greyed; enabled ones link with `?bis_source=wowhead`
  (URL state so deep links and refreshes preserve the choice).
- The active tab highlights and the table rebuilds with that source's
  per-slot BiS data.
- Header text changes from "vs MID1_Paladin_Retribution" to "vs
  Wowhead BiS for Holy Paladin (captured 3 days ago)".

A subtle but important detail: officers landing on a healer page
should see *some* tab active, not "no source" empty. The default
picker should fall through (`simc` → `qe` → `wowhead` → ...) so
healers default to whichever source has data, while DPS keeps
defaulting to SimC.

## Per-source ingest paths

| Source | How we get data | Cadence | Why this cadence |
|--------|-----------------|---------|------------------|
| simc | already wired (`simc:pull --fetch`) | weekly | tracks SimC repo |
| wowhead | scrape per-spec BiS page (HTML, parse item links) | weekly post-reset | tunings ship Tuesdays |
| method | scrape the published roster's gear via wowhead-id mapping | weekly | follows raid release |
| qe | hand-curate from QE site BiS recommendations into JSON | manual | QE doesn't ship machine-readable BiS; user-driven refresh |
| manual | `database/data/manual-bis-profiles.json` + `bis:seed-manual` | on edit | escape hatch for "I just got a kill, here's the actual BiS" |

Wowhead is the highest-leverage second source: most maintained, has
a page per spec, and the URL pattern is stable (`/guides/{class}-{spec}-bis-pve`).
Method comes next if their BiS pages have a stable structure to
parse. QE last because the user's earlier investigation confirmed
QE doesn't expose static BiS - it'd be hand-curated, which is the
same workflow as `manual`. Could fold QE into `manual` and just
attribute the source field.

Each ingest path that's a scrape needs:
- Robots.txt check
- A small parser that lifts item ids out of the page (Wowhead pages
  link to `/item={id}` heavily; should be straightforward)
- Idempotent upsert keyed on (class, spec, hero_talent, source)
- A `--source=` flag option on a unified `bis:pull` command, or
  separate commands per source

## Migration path

The trick is not regressing the working flow. Order:

1. **Add `source` column with default `'simc'`**, plus the new unique
   index. Existing data lights up unchanged because `compareForMember`
   without a `$source` filter still picks the only-row-per-spec.
2. **Update `bis:seed-healers` to write `source = 'manual'`** (or
   `'qe'` if we want to call those rows that). Existing healer stubs
   get retroactively tagged.
3. **Add the tab UI** but render only the existing source for now;
   no new tabs visible because no other sources are populated yet.
4. **Wire the first second source** (Wowhead recommended). Now tabs
   start appearing on character pages where Wowhead has data.
5. **Wire subsequent sources** opportunistically. Each one is
   independent.

At any point the work can pause and the previous step's behaviour is
still useful.

## Storage budget

Per-row `parsed_data` is small (~5 KB for a full BiS profile with 16
slots, gems, enchants, consumables). With:

- 13 classes × ~3 specs each ≈ 40 spec rows per source
- 1-3 hero-talent variants per spec ≈ 80 rows per source
- 5 sources

Upper bound is ~400 rows × 5 KB = ~2 MB. Trivial. Headroom is fine
even alongside the `member_social_snapshots` table.

## Open questions for the next session

1. **Source priority order for the default tab.** simc > qe > wowhead
   > method > manual is one defensible order; method first might be
   right for high-end raiders. Could be a per-officer preference
   eventually, but ship one default first.
2. **Stale-data display.** Each source has its own captured_at;
   should we show "stale" warnings when a source's data is older
   than e.g. 30 days?
3. **Hero-talent overrides per source.** SimC ships hero-talent
   variants; will Wowhead? If not, pickBestProfileFromGear's
   tie-break-on-default logic needs to handle "this source only has
   one row, pick it regardless of hero talent overlap."
4. **Officer-only or visible to members.** Current dashboard is
   officer-tier; future member-tier might want this too. Same routes,
   no new permission story unless we decide to.
5. **What to do about the existing `bis_profiles` rows when their
   source label changes.** Keep current rows, label them `simc`,
   move on. No data migration beyond the column addition.

## Cost estimate

Loose, in days of focused work:

- Schema migration + service refactor + default-source picker: ~0.5
- Tab UI (no new sources, just structure): ~0.5
- Wowhead ingest (scrape + parse + scheduled command): ~1
- Method ingest (parse + scheduled command): ~1
- QE / Manual hand-curation: as much or as little as wanted
- Stale-data warnings, polish: ~0.5

Total scope to "tabs working with two real sources": ~3 days. Each
additional source is ~1 day on top.

## When to actually pick this up

Trigger conditions that would make this worth doing now:

- Officers report disagreeing with SimC's BiS often enough that they
  ignore the comparison column.
- Healer adoption is high enough that "stub profile + actual gear"
  isn't enough; players want concrete recommendations.
- A new tier ships and we want to onboard the new BiS lists from
  multiple sources at once rather than racing SimC.

Trigger conditions that say *don't* bother:

- Officers are mostly using the BiS-issues count column for
  enchants/gems readiness, not the per-slot item comparison.
- The healer stubs + actual-gear listing already cover the use case.
- Hostinger's quota is tight after some other feature ships and
  we're avoiding new tables.
