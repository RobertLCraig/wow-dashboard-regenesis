# Companion addon: writing dashboard changes back to WoW

**Status**: design parked, not yet built. Captures the round-trip
architecture for the moment we want it.

## Why this exists

Per the [WoW = source of truth principle](../../C:/Users/r/.claude/projects/c--Dev-Regenesis/memory/feedback_wow_source_of_truth.md),
the dashboard cannot own mutable state for anything WoW already owns.
Right now the only round-trip mechanism is the macro-paste pattern
(commit `d56a06a` for kicks; same model would apply to note edits).
Macro paste works fine for one-off edits but doesn't scale: 23 note
edits = 23 paste-and-runs.

A companion addon would let the dashboard generate a single
**pending-changes file** that an officer applies in one click in-game.

## What we found (from C:/Dev/WoWAddons/wow-ui-source audit + Battle.net API research)

1. **Battle.net public API is read-only**, has been for 18 years, no
   sign of changing. No write OAuth scopes. Macro paste / addon
   round-trip is the only sanctioned path.
2. **In-game Lua API does have write functions** but they're all
   secure-restricted (`SecretArguments = "AllowedWhenUntainted"`):
   - `C_GuildInfo.SetNote(guid, text, isPublic)` (note: 31-char ceiling)
   - `C_GuildInfo.SetGuildRankOrder(guid, rankOrder)` (promote/demote)
   - `C_GuildInfo.RemoveFromGuild(guid)` (kick)
3. **Secure-restricted = needs a hardware event**. The functions
   cannot fire from passive addon load (e.g. when SavedVariables
   loads). They CAN fire from a button OnClick / keybind handler
   triggered by a user. One click can fire many secure calls.
4. **Permission gates** apply per-call: `CanGuildPromote()`,
   `CanGuildDemote()`, `CanGuildRemove()`, `C_GuildInfo.CanEditOfficerNote()`,
   `CanEditPublicNote()`, plus `IsGuildRankAssignmentAllowed(guid, rankOrder)`
   for rank changes (no promote-above-self, no demote-to-own-rank).
5. **All writes operate on player GUIDs**, not names or roster
   indices. The dashboard already stores GUIDs via the GRM ingest
   (`members.guid`) so we have them.

## Architecture

```
┌──────────┐    1. user makes edits in dashboard
│Dashboard │       (notes, rank changes, kicks)
│  (web)   │
└────┬─────┘
     │ 2. dashboard exports pending changes
     │    as a Lua table
     ▼
┌────────────────────────────────────────────┐
│ RegenesisDashboard_Pending.lua             │
│ (SavedVariables-shaped file)               │
│                                            │
│   RegenesisDashboardPending = {            │
│     generated_at = 1714234567,             │
│     changes = {                            │
│       { kind="set_note",                   │
│         guid="Player-3725-12345678",       │
│         text="...", is_public=false },     │
│       { kind="set_rank",                   │
│         guid="...", rank_order=3 },        │
│       { kind="remove",                     │
│         guid="..." },                      │
│     }                                      │
│   }                                        │
└────────┬───────────────────────────────────┘
         │ 3. file lands in
         │    WTF/Account/<acct>/SavedVariables/
         │    RegenesisDashboard.lua
         │    (via desktop helper or manual drop)
         ▼
┌──────────────────────────────────────────────┐
│ RegenesisDashboard addon (in-game)           │
│                                              │
│  - reads pending file on /reload             │
│  - shows panel:                              │
│      "23 pending changes - [Apply][Review]"  │
│  - on [Apply] click (hardware event):        │
│      foreach change:                         │
│        switch on kind, call C_GuildInfo.X    │
│        record success/failure                │
│  - writes results back to its own            │
│    SavedVariables for dashboard to ingest    │
│    on next sync                              │
└──────────────────────────────────────────────┘
```

## Workflow steps

1. **Officer (or GM) makes edits in the dashboard.** Each edit goes
   into a server-side `pending_changes` table (or just a
   user-scoped queue). Dashboard never writes directly to in-game
   state.
2. **"Export pending changes" button.** Generates the Lua-table file
   on the server, returns it as a download. File contents include
   only the changes, the player GUIDs, and a generation timestamp.
3. **File reaches the officer's machine.** Three options, ranked:
   - **A**: PowerShell helper script (mirroring the existing
     [tools/grm-sync/grm-sync.ps1](../../tools/grm-sync/grm-sync.ps1)).
     Polls the dashboard for pending changes; drops the file into
     the right SavedVariables folder. User runs the same scheduled
     task that already exists.
   - **B**: Browser download + manual drop. Simpler for the v1.
   - **C**: Custom URI scheme + protocol handler that registers
     `regenesis://apply-changes`. Overkill.
4. **Officer logs in / `/reload`s WoW.** SavedVariables loads.
5. **Addon panel appears** showing pending changes count + summary.
   Officer clicks Apply (or Review one-by-one).
6. **Apply click iterates the table**, calls the in-game API for
   each change, records `{guid, kind, success/error}` results.
7. **Addon writes the results back to its own SavedVariables**.
   Next dashboard sync ingests the results, marks the queue
   entries as applied or failed.

## Constraints to design around

- **31-char note ceiling**. Both officer and public notes cap at 31
  characters (Blizzard limit). Dashboard UI must enforce this on
  edit; OR queue truncation feedback so the officer knows the note
  was clipped. The user's previous concern about a parallel
  `dashboard_note` column allowing longer text is real: a 200-char
  dashboard note CANNOT round-trip and would silently truncate.
- **Permission rank gating**. The addon must pre-flight every change
  through `CanGuildPromote`, `CanEditOfficerNote`, etc. Some
  changes will be valid for some officers and not others. Failed
  changes should report back, not silently drop.
- **Stale GUIDs**. If a dashboard change targets a GUID that's no
  longer in the guild (e.g. the player left between dashboard edit
  and apply), the addon should skip and report. Don't error the
  whole batch.
- **Idempotency / replay**. The addon should write back which
  changes succeeded so a second `/reload` doesn't re-apply them.
  Dashboard server marks the queue entry "applied" once the
  results come back.
- **Concurrency**. Two officers might both have pending-changes
  files. Each addon installation reads its own file; conflicts
  resolve at the dashboard level (last apply wins, but the queue
  records both attempts).

## Open questions for the build session

1. **Where does the queue live server-side?** Options: dedicated
   `pending_changes` table; or piggyback on `MemberAction` (already
   has actor + target + outcome columns).
2. **Per-user queue or shared?** If two officers both edit Bob's
   note, do both apply attempts run? Lean: per-user queue, last
   apply wins.
3. **How does the addon know which file to read?** Either fixed
   filename (`RegenesisDashboard_Pending.lua`) or the dashboard
   posts to a known bucket via the helper script.
4. **Do we ship the addon via CurseForge / WoWUp?** Or just provide
   a zip + install instructions? (CurseForge requires a different
   level of polish + ongoing release management.)
5. **What does the panel UI look like for the GM specifically?**
   Per the accessibility-guide work, the GM has visual
   constraints. The in-game frame would need to be designed for
   high-clarity legibility, or we accept that an officer (not the
   GM) is the typical operator of the apply step.

## What we explicitly DON'T need to build

- HTTP fetching from inside the addon. WoW's Lua sandbox forbids it.
  All data flow is via SavedVariables files that the desktop helper
  (or manual drop) places.
- Two-way sync of every dashboard field. Only the writeable fields
  need to round-trip; everything else stays read-only ingest.

## Cost estimate (if we build later)

- Addon scaffolding (.toc, init Lua, panel frame): half a day
- Pending-change handlers (one per kind): half a day
- Result write-back + dashboard ingest path: half a day
- Desktop helper integration with the existing PowerShell script: a
  day, including testing on the user's actual machine
- Packaging + install docs: half a day

Plus an indeterminate ongoing maintenance cost: when Blizzard
patches the secure-restricted API surface (rare but it happens),
the addon needs an update.

## When to revisit

- When the volume of macro-paste edits hits a real friction
  threshold (>5 edits per session feeling annoying).
- When the GM specifically asks for richer note editing (because
  the current macro-paste flow either doesn't fit her workflow or
  the 31-char ceiling is too constraining and we want to revisit
  that whole question).
- After a stretch of "boring" dashboard work where this would be
  a refreshing change of pace and the architectural picture is
  fresh in mind.
