# M+ run tracker companion addon (Path B)

**Status**: deferred. Not built yet. Captured here so we know where to
pick up if and when we want full per-run fidelity for Mythic+.

## Why this exists

The dashboard already has a per-run M+ history feature (Path A, RIO
sampling, see [the runs table migration](../../database/migrations/)
and [RaiderioSnapshotImporter](../../app/Services/Raiderio/RaiderioSnapshotImporter.php)).
That path samples Raider.IO every 3 hours and dedupes by completion
timestamp. It captures roughly every run for typical players because
RIO's `mythic_plus_recent_runs` returns the 10 most recent and we
sample faster than anyone realistically completes 10 keys.

Where Path A is lossy:

- **Heavy pushers** running >10 keys in a single 3h window (rare but
  real, e.g. a group hammering the same dungeon for a title push).
- **Depleted / abandoned keys**. RIO only surfaces completed runs
  (timed or untimed). A bricked key that the group abandoned at boss
  one is invisible.
- **Group composition**. RIO recent_runs gives the dungeon, level,
  time, and upgrades, but not the four other party members.
- **RIO outage / API churn**. If RIO disappears or rate-limits us,
  Path A goes dark.

For "did Joe run anything this week" or "is the team mostly running
+10 to +12s", Path A is sufficient. Path B is the upgrade for "every
single key, including failed ones, with full party context."

## What an in-game addon could capture that the API can't

WoW fires a handful of events around M+ that the in-game Lua API
exposes fully:

- `CHALLENGE_MODE_START` - keystone activated, level + dungeon known
- `CHALLENGE_MODE_COMPLETED` - run finished (timed or over). Returns
  map ID, level, time taken, on-time flag, keystone upgrades, any new
  best, affixes, members
- `CHALLENGE_MODE_RESET` - key was reset / abandoned mid-run
- `WORLD_STATE_TIMER_START` / `WORLD_STATE_TIMER_STOP` - timer events
- Party roster snapshots via `GetGroupMemberInfo` etc. at run start

Combined with `C_ChallengeMode.GetActiveKeystoneInfo()` and party
roster reads, an addon can record a per-run row containing:

- start timestamp + end timestamp
- map ID + dungeon name + level + affixes (current week's set)
- timed flag + duration + upgrades earned
- depleted flag (true if `RESET` fired before `COMPLETED`)
- party: 5 entries of `{name-realm, class, spec, role}`
- player's own GUID (for matching back to dashboard members)

This is strictly more data than RIO, Blizzard, or anywhere else
exposes.

## Architecture sketch

Same shape as the existing GRM ingest. Read-only. No write-back,
no secure-restricted calls, no permission gates. Pure logger.

```
in-game addon (RegenesisMplusLog)
        |
        | writes one row per run to SavedVariables
        v
WTF/Account/<acct>/SavedVariables/RegenesisMplusLog.lua
        |
        | desktop helper picks up the file
        | (same PowerShell pattern as tools/grm-sync/)
        v
PHP parser (analogue of the GRM parser, server-side)
        |
        | upserts into member_mplus_runs with source='addon'
        v
existing dashboard UI (heatmap, dungeon distribution, etc.)
```

Schema-wise, the existing `member_mplus_runs` table from Path A is
already shaped to absorb addon rows: keep the unique key on
`(member_id, completed_at, dungeon_id)` so a RIO-sourced row and an
addon-sourced row for the same key collapse to one. Add a `source`
enum (`recent`, `weekly_best`, `prev_weekly_best`, `season_best`,
`alternate`, `addon`) and let the addon source win on conflict (it
has the truth, including party + depleted flag).

## Why we deferred

- Path A ships in a day; addon ships in a week and needs every
  raider to install it.
- Adoption is the killer: a tracker that only some players run gives
  asymmetric data. Officers seeing "Bob has done zero keys this
  month" can't tell if Bob is inactive or just hasn't installed the
  addon. Worse than no data.
- The data we'd gain (party comp, depleted keys) is nice-to-have,
  not load-bearing for the raid leader's "are people running keys"
  question.

## When to revisit

- Officers start asking questions Path A can't answer ("who did Joe
  run that key with?", "how many bricks per week is the team
  eating?").
- Push players want a depletion ledger for self-improvement.
- RIO becomes unreliable or starts charging for the API surface we
  rely on.
- If the user is already going to write a small addon for another
  reason (e.g. the write-back addon in
  [companion-addon.md](companion-addon.md)) and adding M+ logging
  is incidental.

## Cost estimate (when we build)

- Addon scaffolding + event handlers: half a day
- SavedVariables format + dedupe key design: half a day
- Desktop helper / upload integration with existing GRM pipeline:
  half a day if we reuse, full day if we build fresh
- PHP parser + ingest into `member_mplus_runs`: half a day
- Adoption nag (UI badge for "addon not detected" on character
  pages, install instructions doc): half a day

Total: ~2.5 days clean build, plus rollout effort to get the team
to install it.

## What this doc deliberately doesn't cover

- WCL upload integration. WCL has M+ logs but only when groups upload
  them, which our team does not do reliably. Same adoption problem
  as the addon, with no upside.
- Scraping logs.raider.io / mythicstats.com. Those aggregate the
  same Blizzard data we already get, often a day stale. No win.
