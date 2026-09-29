# Filling in the healer BiS profiles

## What I need from you

**Pick 1, 2 or 3 below.** It decides whether roughly seven healer specs get hand-curated per-slot
item lists, which is the largest piece of manual data entry left in the project.

My recommendation is **2**, with the empty column changed to say why it is empty.

**Pass** is a number in this card. If it is 2, the only work is one line of Blade, and this closes
the same day.

**Fail** is leaving the column silently empty, which is where it is now. The first healer who looks
finds nothing and concludes the feature is broken, and that is exactly the impression the stub
shells were seeded to prevent and only partly do.

**Why it needs you** The trigger is whether the guild's healers actually use the character page.
Nothing in the repository knows that, and it is the fact that decides whether seven specs of data
entry is worth anything at all.

## Why
SimC's MID1 directory ships DPS and tank profiles only, so `bis_profiles` has no source for healer
specs. Stub shells were seeded on 2026-04-30, which is why the character page renders consumables
and an explainer badge rather than a blank widget, but the per-slot comparison column stays empty
for every healer. The multi-source ingest plan for filling them is in
[docs/planning/multi-source-bis-tabs.md](../../planning/multi-source-bis-tabs.md).

## Options
1. **Hand-curate now, from Wowhead or Icy Veins.** Roughly seven specs of per-slot lists. Cost: a
   long manual pass, and it goes stale at the next tier, so it is a recurring cost rather than a
   one-off.
2. **Wait for a healer to ask.** Leave the shells and fill a spec when someone actually looks.
   Cost: the first person to look finds an empty column and concludes the feature is broken, which
   is the impression the stub shells were seeded to avoid and only partly do.
3. **Build the multi-source ingest first**, then fill from it. Cost: real engineering before any
   healer benefits, and the ingest is only worth it if several tiers of use follow.

## Recommendation
Option 2, with one change that makes it honest: the empty column should say why it is empty and
name this card, rather than simply rendering nothing. That turns "looks broken" into "known gap,
say the word", which is the actual state, and it costs one line of Blade rather than seven specs of
data entry.

Option 1 is right if healers are already using the page. Option 3 is right only if the answer to
option 1 is yes and you expect to do it again next tier, because the ingest pays for itself over
repeats rather than over the first fill.

## Decided


**2026-08-15** 3

## Comments
<!-- The card's thread, appended by ProgressBoard. Append-only: entries are added, never edited or removed. An entry beginning **Decided:** is an answer, and that is what a decision card exits on. -->

**2026-08-29** The loop moved this card from todo/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 3 of 3 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

**2026-09-29** The loop moved this card from todo/ to human-review/. 5 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 3 of 3 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

**2026-09-29** Returned to todo/ by an attended session. The park above was not this card's: `Get-NoProgressCounts` in ProgressBoard's `bin/work-card.ps1` read another board's holds for the same card number out of the shared log, so it parked this card with no take of its own since it was sent back. Fixed in ProgressBoard `6d49915`, which reads each log line's board from its run id.
