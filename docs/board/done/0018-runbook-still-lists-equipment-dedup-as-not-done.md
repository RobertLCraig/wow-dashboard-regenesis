# Runbook still lists the equipment dedup as not done

## Why
`docs/ops-runbook.md`, section "Follow-ups (not yet done — further headroom, in priority order)",
item 1 asks for dedup-on-write for `member_equipment_snapshots` with a per-member hash column. Card
0003 built that dedup, without a hash column, in `EquipmentSnapshotImporter::pull`. The next reader
of the runbook is told to build it again, and in a shape the card rejected because a migration
cannot run while production writes are revoked.

It happened because card 0003 changed the importer and nobody went back to the runbook.

## Links

**Relates to**
- `0003` - built the dedup this runbook item still asks for; its `## Plan` says why there is no hash
  column.

## Not this card
Any code change. Any other runbook item.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a reader opens the runbook follow-ups, THE RUNBOOK SHALL no longer list equipment dedup
      as not done, and SHALL say where it was built. proves: none - a doc edit, nothing to run
<!-- AC:END -->

## Tasks
- [x] Move item 1 out of "not yet done" and point it at `EquipmentSnapshotImporter::pull` and card 0003

## Comments

**2026-09-28**
RESULT: done
TESTS: +0 new, all green (AC #1 is proves: none)
TOUCHED: docs/ops-runbook.md
TOUCHED: docs/board/in-progress/0018-runbook-still-lists-equipment-dedup-as-not-done.md
OUT-OF-SCOPE: none

The dedup entry now sits under "Prevention (shipped)", pointing at `EquipmentSnapshotImporter::pull`
and card 0003, and saying why there is no hash column (checked against 0003's Plan). The follow-ups
keep their numbers 2 and 3, with a one-line note on where item 1 went: card 0004 cites "follow-up 2"
three times for the `raw_json` table, and renumbering would have broken that and touched items this
card rules out. No browser check applies; it is a doc.

### 2026-09-29 review (v20260929003041-1a41)

**suite**

`vendor\bin\pest.bat` exited 0 after 111s, run by this job rather than reported by the card.

**acceptance: sound**

I tried to break the one criterion, and I could not.

**#1 is met.** I read `docs/ops-runbook.md`.

- The "Follow-ups (not yet done)" section no longer lists the equipment dedup (dedup means: do not save the same gear twice). The list now starts at item 2. A note says item 1 shipped and moved to Prevention.
- The "Prevention (shipped)" section now has the dedup entry. It names `EquipmentSnapshotImporter::pull` and card 0003.
- It says why there is no hash column. A migration cannot run while production writes are blocked. That matches card 0003's Plan.

The runbook points at real code. `EquipmentSnapshotImporter::pull` is in `app/Services/Blizzard/EquipmentSnapshotImporter.php`. It moves the unchanged row onto the new snapshot and does not write a new one. The 2026-09-28 review of card 0003 confirmed this too.

No criterion is disproved.

VERDICT: sound

**scope: sound**

The scope is sound for card 0018. I found nothing to fix.

**What I checked:**

- Card 0018 changed only `docs/ops-runbook.md` and its own card file. That matches the card's "Not this card" limit: no code changes and no other runbook items.
- In `docs/ops-runbook.md`, the dedup entry now sits under "Prevention (shipped)". It names `EquipmentSnapshotImporter::pull` and card 0003. It also says why there is no hash column: a migration cannot run while production writes are revoked.
- The "Follow-ups (not yet done)" section no longer lists item 1. Items 2 and 3 are not changed. The builder added one note that says where item 1 went. The note does not touch items 2 or 3, so it stays inside the limit.
- The big diff also shows code, test and HANDOVER changes. Those belong to other cards (0003, 0005, 0008, 0013, 0019, 0021, 0022). They are not over this card's limit.

**Nothing is half done.** Criterion #1 is met.

VERDICT: sound

**breakage: sound**

I tried to break this change. I could not.

**What I checked:**

- **The runbook.** In `docs/ops-runbook.md`, the dedup entry now sits under "Prevention (shipped)". It names `EquipmentSnapshotImporter::pull` and card 0003. It says why there is no hash column. The "not yet done" list no longer has it.
- **Links into the runbook.** Card 0004 cites "follow-up 2" and "items 2 and 3". The numbers were kept, so those links are still true. The HANDOVER link points at the follow-ups heading, and that heading did not change.
- **The runbook's own words.** It says the importer "moves" the unchanged row. That is true. The runbook leaves out that the importer can also delete one duplicate row, but that does not make its words false.

**One stale line, not caused by this card.** `docs/planning/next-session.md`, section "0b", still lists "equipment dedup-on-write" as a follow-up that is not done yet. That line was already wrong when card 0003 shipped. This card did not make it wrong. The card's criterion covers only the runbook, so this line does not disprove #1. It should go on its own card or into a planning-doc cleanup.

No criterion is disproved.

VERDICT: sound

