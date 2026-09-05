# HANDOVER: Regenesis officer dashboard

> A Laravel dashboard for the officers of the Regenesis (Silvermoon-EU) World of Warcraft guild. It
> pulls roster, gear and attendance data from five outside sources and renders them as officer
> widgets. Picked up by an agent session working one board card.

**Stage:** shipped (live at `regenesis.enhanceify.co.uk`, still under active development)
**Status:** every surface in the README's "What's built" table is live. Open work is the board, and
most of it is decisions owed rather than code.
_Last updated: 2026-09-05 (first handover; written by card 0015, which existed because there was none)_

## Goal & success criteria

**GAP: there is no PRD.** Nothing in this repository states the goal or what "success" would be, so
the following is read back off the code and the README, and it is an interim rather than a source of
truth. `docs/PRD.md` is owed.

**The goal, as the code shows it.** Give guild officers one screen that answers the questions they
otherwise answer by hand: who joined or left, who has gone quiet, who is under-geared for the next
raid, who signed up and did not turn up, and what is on this week. It reads from five outside
sources so no officer has to.

**Success criteria: UNKNOWN, confirm with Rob.** No adoption target, no "the dashboard has replaced
X" statement, and no acceptance for the project as a whole is written down anywhere in the repo.
Individual cards carry their own acceptance; the project does not.

## Canonical data shape

**Identity is `guild_key` plus `name`, and `name` is `"Char-Realm"`** (for example
`Totemtaeven-Silvermoon`). That is the key GRM uses in its SavedVariables table, and it is the only
identity stable across pulls: a character's Blizzard GUID changes on a realm transfer. `members` has
`unique(guild_key, name)`.

Four tables carry the model. Read the migrations for the full column lists; their inline comments are
the authority and are unusually good.

| Table | Holds | Shape |
|---|---|---|
| `members` | current state, one row per character | `status` is `active` / `left` / `banned`. `main_member_id` is a self-FK, and NULL means "this member IS a main". Soft-deleted, never hard-deleted. |
| `snapshots` | one row per source pull | `source` is the discriminator (`grm`, `wowaudit`, `blizzard`, ...). `unique(guild_key, source, payload_hash)` is the dedup. |
| `member_snapshots` | per-member volatile fields per pull, plus `raw_json` | The fat one, about 14 KB a row. |
| `member_events` | derived signals, the timeline the widgets read | `snapshot_id` is nullable, because anniversaries and inactivity are computed rather than diffed. Tiny, and the only history the UI needs. |

`member_events.type` is an enum of `joined`, `returned`, `left`, `kicked`, `banned`, `promoted`,
`demoted`, `level_up`, `note_changed`, `marked_for_promote`, `marked_for_demote`,
`marked_for_kick`, `became_inactive_30d`, `anniversary`. The detection rule for each one lives in
`App\Services\Grm\GrmSnapshotDiffer`, and that is the one place they are written down.

**The four `member_*_snapshots` side tables are append-only fat JSON, and they are the thing that
filled the production database.** `member_equipment_snapshots` (gear, about 43 KB a row),
`member_mplus_snapshots`, `member_raid_snapshots`, `member_social_snapshots`. Every widget reads only
the latest row per member per source. See the ops runbook before touching any of them.

**Where a value has several sources, a resolver picks one, and the order is deliberate.** Blizzard is
first because it refreshes within minutes of a character logging out, ahead of Raider.IO's scrape.

- **Item level:** Blizzard, then Wowaudit, then Raider.IO. The roster cell carries a `via {source}`
  tooltip so the answer is never anonymous.
- **Equipped gear and active spec (BiS panel):** Blizzard `/character/equipment`, then Raider.IO,
  then the most recent Warcraft Logs parse.

**Permission tiers, lowest to highest:** `member` (1), `raid_leader` (2), `officer` (3), `big6` (4),
`gm` (5), in `App\Models\User::TIER_RANK`. Tier is derived from the user's Discord roles at login.
`RequireTier` middleware with no argument means officer and above.

**Known divergence, already recorded:** `member_snapshots.raw_json` has four live readers, one of
them a Blade widget, listed with their exact call sites in
[`docs/ops-runbook.md`](ops-runbook.md#follow-ups-not-yet-done--further-headroom-in-priority-order).
Read that table before proposing to thin or drop the column.

## Architecture / stack

Laravel 12 on PHP 8.2+ (8.4 locally via Herd). Blade plus Alpine.js plus Chart.js, no SPA, Tailwind
by CDN. Livewire 4 is installed. Pest 4 for tests. MySQL on Hostinger, SQLite in dev and in the
suite. Vite builds assets, and because Hostinger has no Node, `public/build/` is committed to git and
ships by `git pull`.

The flow diagram is in [`README.md`](../README.md#how-the-pieces-fit) and is accurate. In one line:
a scheduled PowerShell task on Rob's WoW PC posts the GRM addon's SavedVariables to
`POST /api/ingest/grm`, a queued job diffs it into `members` and `member_events`, and a dozen
scheduled artisan commands pull the outside sources on their own cadences.

## Key files / structure

The README's "Repo layout" block is the map and is still correct. Only the non-obvious seams follow.

- `routes/console.php` is the whole schedule, one `Schedule::command()` per source, each with the
  reason for its cadence in a comment. It is the fastest way to learn what this app actually does
  over a day.
- `app/Services/` is one directory per outside source or concern (`Grm`, `Blizzard`, `Raiderio`,
  `Wcl`, `Wowaudit`, `Simc`, `RaidHelper`, `GoogleCalendar`, `Discord`, `Bis`, `Digest`, ...). New
  integration work goes in a new directory here, not into a controller.
- `app/Services/Grm/GrmSnapshotDiffer.php` turns two snapshots into `member_events`. Every derived
  signal the dashboard shows starts here.
- `config/` has a file per integration (`blizzard`, `raiderio`, `wcl`, `wowaudit`, `simc`, `grm`,
  `raidhelper`, `discord`, `digest`, `snapshots`, `dashboard`). Thresholds and caps live there
  deliberately, because they belong to the hosting plan or the season rather than to the code.
- `tools/grm-sync/` is the PowerShell sync plus its Task Scheduler XML. It runs on the WoW PC, not
  on the server.
- `database/data/healer-bis-profiles.json` exists because SimulationCraft ships no healer profiles.
  Its rows are deliberate stubs.

## Decisions locked

**GAP: there is no `docs/DECISIONS.md`, and `docs/board/done/` is empty.** No card has ever been
accepted, so there is no decided-card record to read. The decisions below were recovered from the
README, the ops runbook and `docs/planning/next-session.md`. That is three homes for one concern, and
a decision log is owed.

- **Sessions and cache are on the `file` driver, never `database`.** On 2026-07-08 the database hit
  its cap, Hostinger revoked writes, and because `StartSession` writes a row on every request, every
  route returned 500 including the static landing page. Moving both to `file` means a database
  outage no longer takes the public site down. Do not move them back.
- **Snapshot history is expendable; latest state is not.** `snapshots:prune` deletes aged rows but
  always keeps each member's latest row per source. Churn and anniversary history lives in the small
  `member_events` table, which is why the fat tables can be pruned at all.
- **`public/build/` is committed.** Hostinger has no Node, so the built assets ship through git.
- **Every integration no-ops when its key is empty**, and logs that it is skipping. That is why cron
  can stay armed for all of them on a machine that has only some of the credentials. A quiet cron run
  is therefore not evidence of a fault.
- **Pulls are batched at `--limit=100`, oldest-first.** Hostinger kills PHP at 30 seconds, so a full
  roster sweep is spread over about four hours rather than done in one tick.
- **The identity key is `Char-Realm`, not the Blizzard GUID**, because GUIDs change on transfer.
- **The custom data-collection addon was rejected**, and sits in `docs/board/discarded/0012` with the
  reasoning.

## Current state

- **Done:** everything in the README's "What's built" table is live in production. Discord OAuth with
  tiered access, the roster and character pages, the BiS comparison against SimulationCraft profiles,
  the Social events hub with its calendar grid and ICS feeds, the Raid-Helper event creator and
  webhook receiver, Warcraft Logs ingest and parse widgets, the composition planner, the weekly
  digest, Google Calendar push, and the drag-and-drop dashboard layout editor. The suite is Pest and
  covers the parsers, the importers, the auth gating, the webhook and the ICS output.
- **In progress:** `0004` (reclaim the space the snapshot tables took) is parked in `in-progress/`
  carrying `not_for_the_loop:`, because the step left is an `OPTIMIZE TABLE` on the live host that
  only a person can run. `0015` is this document.
- **Known bugs / broken:** production may still have no write grants, see Blockers. In this
  repository, `app/Services/Raiderio/RaiderioSnapshotImporter.php` fails Pint; that is card `0014`'s
  unfinished business and the card is in `human-review/` for it.

## What's next (in order)

The queue is [`docs/board/todo/`](board/), one card per file, and the folder a card sits in is its
state. Read [`docs/board/README.md`](board/README.md) once before working one.

- `0001` blocks the only other card in a work lane, so it goes first even though it is a person's.
- `0010` and `0011` are both decisions rather than builds, so an agent session cannot clear either.

**Read this before picking a card: the queue is thinner than it looks.** Of the fourteen cards in
work and person lanes, two are in `todo/` as decisions and one is blocked. An agent session looking
for something to build will mostly find things waiting on Rob.

## Blockers / open questions

The person's queue is [`docs/board/human-review/`](board/), nine cards deep. Each carries its own
options and recommendation, so they are not restated here. Three things need saying:

- **`0001`'s `waiting_on:` recheck date was 2026-08-20 and has passed.** It is the oldest thing on
  the board and `0004` is blocked behind it. Until somebody logs in to the live site and confirms
  the database write grants came back, production may still be read-only.
- **`0014` cannot be cleared by an agent.** All three of its criteria are ticked and a reviewer
  disproved one, but a reviewer may not untick a box, so every session finds nothing open to do and
  the loop promotes it again. It needs Rob to untick or to say the finding is wrong. The card's own
  last comment explains this.
- **`0013` is a rewrite of the board's own cards** and it is the reason several cards read the way
  they do.

## How to pick up

From PowerShell, in the repository root.

```powershell
.\vendor\bin\pest.bat          # 748 tests, about 26s, sqlite in memory
.\vendor\bin\pint.bat --test . # style. THE TRAILING DOT IS NOT OPTIONAL, see below
php artisan serve --port=8000  # or use Herd at https://regenesis.test
```

**TRAP: `.\vendor\bin\pint.bat --test` with no path exits 0 having checked nothing.** Reproduced on
2026-09-05 in this repository: with no path argument Pint scans the working directory as an absolute
path with no trailing separator, matches zero files, and reports clean. The same run as
`pint --test .` exits 1 and names a real offender. Every spelling except the bare absolute root is
correct, including `.`, `./` and even the absolute root with a trailing `\`. Card `0016` covers the
fix; until it lands, **always pass the dot**. This is not theoretical: it is how card 0014 shipped a
false clean, and the style step in every card session's instructions is the bare form.

**TRAP: a browser check run from a git worktree proves nothing.** Herd serves this project from
`C:\Dev\Regenesis` whatever worktree you are standing in, so `regenesis.test` will show Rob's tree
and not yours. Say in your card comment that a browser check is still owed, rather than reporting one
as passed.

**TRAP: the two database size meters are different numbers and the gap has been gigabytes.**
`php artisan db:show` reads rows in use; Hostinger meters the files on disk. `DELETE` moves the first
and never the second, so pruning can run for six weeks and shift the host's meter by nothing. Read
[`docs/ops-runbook.md`](ops-runbook.md) before any work that touches the snapshot tables, and use the
`data_free`-inclusive query it gives.

**Never edit `.env`.** In a card worktree it is a hard link to Rob's own file, so a change reaches
his tree. Credentials are named in the README's first-time setup and their values live only there.

## Suggested skills / next tools

| Reach for | When |
|---|---|
| `/handover resume` | starting fresh: reads this file and the board, then starts the head card |
| `/handover save` | ending a session: reconciles the board and updates this file |
| `/review-card` | a card is sitting in `docs/board/ai-review/` |
| `/diagnosing-bugs` | something is broken, slow or silent, before theorising about the cause |
| `/checkpoint` | update the doc set and commit |
| `php artisan tinker` | poking the data shape; the README lists three starter queries |

## Sibling docs

| Doc | Purpose |
|-----|---------|
| [`README.md`](../README.md) | what the app is, the flow diagram, and every credential's first-time setup. Start here. |
| [`docs/ops-runbook.md`](ops-runbook.md) | Hostinger's limits, the 2026-07-08 outage, and the "site is 500ing" runbook. Read before touching the snapshot tables. |
| [`docs/board/README.md`](board/README.md) | the board convention. Read once before working a card. |
| [`docs/accessibility-guide.md`](accessibility-guide.md) | the clarity dial and the accessible-table component |
| [`docs/planning/next-session.md`](planning/next-session.md) | history and reasoning only: the shipped log, the read on the Copilot PRD, the ranking. Its open work has already moved to the board. It is 60 KB, so open it for a specific question rather than for orientation. |
| `docs/planning/{companion-addon,mplus-run-tracker-addon,multi-source-bis-tabs}.md` | unbuilt design sketches |
| `docs/*.txt` | vendor API dumps (Battle.net, Raid-Helper, wowaudit). Reference, several MB, do not load casually. |
| **MISSING: `docs/PRD.md`** | the goal and success criteria have no home |
| **MISSING: `docs/DECISIONS.md`** | decisions are spread across three files |
| **MISSING: `docs/DATA-MODEL.md`** | the shape lives in migration comments only |
| **MISSING: root `CLAUDE.md`** | there is no orient tripwire, so a session can start without reading any of this |

The README also points at `~/.claude/plans/luminous-moseying-bear.md`, which is outside the
repository. Treat it as unavailable.

## Branch status

Written on `card/0015`, cut from `main`. Card branches are merged back by the ProgressBoard
scheduler, not by the session. No PR, and this repository has no GitHub remote to open one against.

## Session log

`git log --format='%ad %s%n%b'`. Commits from the unattended loop come in threes: the lane move in,
the work, the lane move out, so `board: NNNN <from> to <to>` messages bracket every card's real
commit.
