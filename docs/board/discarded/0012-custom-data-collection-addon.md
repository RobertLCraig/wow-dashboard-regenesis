# A custom in-game data-collection addon

## Why
The idea was to write a WoW addon collecting what the external APIs cannot: real attendance,
consumable usage, interrupts and dispels.

## Not this card
The GRM ingest, which already gives roster, alt and note data, and the WCL ingest, which covers
combat.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a raid night ends, THE APP SHALL show attendance, consumable usage, interrupts and
      dispels from data no external API exposes.
<!-- AC:END -->

## Tasks
- [ ] Discarded before any task was started, see the entry below

## Direction
<!-- Steering, appended by ProgressBoard. Append-only: entries are added, never edited or removed. -->

**2026-08-10** Discarded on the reasoning already recorded in
`docs/planning/next-session.md`, not on a new judgement: an addon is a maintenance trap for a
personal tool, every addon breaks on patch day, and the plan's own condition for revisiting it was
"evaluate after WCL lands, only if a specific gap survives". WCL landed on 2026-04-27 with parses,
deaths and top-cause breakdowns, and no specific surviving gap has been named since. Carried onto
the board as discarded rather than dropped silently, so the reasoning stays findable if someone
proposes it again.
