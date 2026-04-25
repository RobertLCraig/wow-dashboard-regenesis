# Regenesis

Officer dashboard for the Regenesis (Silvermoon-EU) WoW guild. Pulls in-game
roster history from the [Guild_Roster_Manager](https://www.curseforge.com/wow/addons/guild-roster-manager)
addon and event/attendance data from [Raid-Helper](https://raid-helper.dev/),
and renders both as officer-friendly widgets, with an event creator that
posts to Raid-Helper, generates per-event `.ics` files, and serves a
`webcal://` subscription feed for Google Calendar.

## How the pieces fit

```
[WoW PC]                                   [Hostinger]                     [Discord / Raid-Helper]
 GRM SavedVariables.lua                    Laravel app                      Discord OAuth
   │  Task Scheduler (30 min)              │                                 │
   ▼                                       ▼                                 │
 grm-sync.ps1 ── lua54.exe ───────► POST /api/ingest/grm (bearer)            │
                                           ├─► IngestSnapshotJob             │
                                           │     ├─ snapshot row             │
                                           │     ├─ upsert members           │
                                           │     └─ diff → member_events     │
                                           ◄──── webhook event.* ────────────┤
 Officer browser ──► Discord OAuth ──► Dashboard                             │
                                           ├─► /events/new ──► RH API ───────┤
                                           ├─► /events/{id}.ics              │
                                           └─► /calendar/{token}.ics (webcal)
```

Plan file: `C:/Users/r/.claude/plans/luminous-moseying-bear.md`.

## Stack

- Laravel 12 / PHP 8.2+ (running PHP 8.4 locally via Herd)
- Pest 3 for tests
- Blade + Alpine + Chart.js (no SPA), Livewire for interactive widgets only
- MySQL on Hostinger; SQLite for local dev
- Vite for asset build (Hostinger has no node, `public/build/` is committed
  and shipped via `git pull`, mirroring [enhanceify-V2](../enhanceify-V2))

## Setup (local)

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install && npm run build
php artisan serve
```

Then fill the empty `DISCORD_*` and `RAID_HELPER_*` keys in `.env`. See
that file for where each value is created (Discord developer portal,
`/apikey` and `/webhooks show` in Discord).

## Deploy

```sh
pwsh ./deploy.ps1            # build, push, run server-side deploy.sh
pwsh ./deploy.ps1 -DryRun    # show what would happen
```

Mirrors [`enhanceify-V2/deploy.ps1`](../enhanceify-V2/deploy.ps1): same
SSH host, same `git pull` + `composer install --no-dev` + `artisan migrate
--force` + `optimize` + `queue:restart` flow on the server side.
