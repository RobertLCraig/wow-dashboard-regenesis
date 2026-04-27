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
   |  Task Scheduler (30 min)              |                                 |
   v                                       v                                 |
 grm-sync.ps1 -- extract.php ----> POST /api/ingest/grm (bearer)             |
                                           +-> IngestSnapshotJob             |
                                           |     +- snapshot row             |
                                           |     +- upsert members           |
                                           |     +- diff -> member_events    |
                                           <---- webhook event.* ------------+
 Officer browser --> Discord OAuth --> Dashboard                             |
                                           +-> /events/new --> RH API -------+
                                           +-> /events/{id}.ics
                                           +-> /calendar/{token}.ics (webcal)
```

Plan file: [`~/.claude/plans/luminous-moseying-bear.md`](C:/Users/r/.claude/plans/luminous-moseying-bear.md).

## Stack

- Laravel 12 / PHP 8.2+ (running PHP 8.4 locally via Herd)
- Pest 3 for tests (40 covering parser, ingest pipeline, Discord auth, webhook, iCal)
- Blade + Alpine.js + Chart.js (no SPA), Tailwind via CDN
- MySQL on Hostinger; SQLite for local dev
- Vite for asset build. Hostinger has no node, so `public/build/` is git-tracked
  and ships via `git pull` (mirrors [enhanceify-V2](../enhanceify-V2))

## What's built

| Surface | Status |
|---------|--------|
| Discord OAuth (officer/big6/GM gated) | done |
| Roster Health stats | done |
| Upcoming Events + Attendance | done |
| Action Queue (Promote/Demote/Kick) | done |
| Anniversaries this week | done |
| Recently Inactive (>30d) | done |
| Alt Group Viewer (search + expand) | done |
| Recent Log Timeline | done |
| Ban List | done |
| Rank Distribution donut | done |
| Churn line chart (12 weeks) | done |
| Event creator -> Raid-Helper API | done |
| Per-event signed .ics download | done |
| webcal:// subscription feed | done |
| Raid-Helper webhook receiver | done |
| Daily attendance snapshot command | done |
| GRM SavedVariables -> JSON sync (PowerShell) | done |
| wowaudit ilvl/vault/M+ data | done (Silver+ tier required) |
| Great Vault Progress widget | done |
| Mythic+ This Week widget | done |
| Roster table page | v2 |
| Per-tier permission Gates | v2 (flat in v1, gates in place) |

## First-time setup

### 1. Local dev
```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install && npm run build
php artisan serve --port=8000
```

You should be able to visit `http://localhost:8000` and see the landing
page. The dashboard requires Discord OAuth (next step).

### 2. Discord application

Create one at <https://discord.com/developers/applications>.

- **Name**: Regenesis Dashboard (or whatever)
- **OAuth2 -> Redirects**: add `https://regenesis.test/auth/discord/callback`
  for local Herd, plus `https://regenesis.enhanceify.co.uk/auth/discord/callback`
  for production.
- Copy `CLIENT ID` -> `.env DISCORD_CLIENT_ID`
- Generate a `CLIENT SECRET` -> `.env DISCORD_CLIENT_SECRET`

The `DISCORD_GUILD_ID` and three role IDs (`DISCORD_ROLE_GM/BIG6/OFFICER`)
default to the Regenesis Discord values; only override if you're on a test
server.

### 3. Raid-Helper

In your Discord server (with Raid-Helper installed):

```
/apikey                                                                              <- gives the SERVER api key
/webhooks set type:event.create url:https://regenesis.enhanceify.co.uk/api/webhook/raidhelper
/webhooks set type:event.update url:https://regenesis.enhanceify.co.uk/api/webhook/raidhelper
/webhooks set type:event.delete url:https://regenesis.enhanceify.co.uk/api/webhook/raidhelper
/webhooks show                                                                       <- gives the webhook key
/webhooks refresh-key                                                                <- rotate if it leaks
```

`/webhooks set` takes one type per call, so run it three times to subscribe
to all the event lifecycle pushes. `/webhooks show` confirms each line and
prints the shared key Raid-Helper sends in the Authorization header.

Drop both into `.env`:
```
RAID_HELPER_API_KEY=<from /apikey>
RAID_HELPER_WEBHOOK_KEY=<from /webhooks show>
RAID_HELPER_DEFAULT_CHANNEL_ID=<right-click your raid-events channel -> Copy ID>
```

### 4. wowaudit (optional, requires a paid tier)

If you have a wowaudit Patreon (Silver+), grab your team API key from
<https://wowaudit.com> -> Settings -> API and drop it in `.env`:
```
WOWAUDIT_API_KEY=<from wowaudit Settings -> API>
```
The hourly `wowaudit:pull` cron job populates the Great Vault progress
and Mythic+ widgets on the dashboard. Runs as a no-op (logs "skipping")
when the key is empty, so cron stays armed without errors. Manually:
```sh
php artisan wowaudit:pull
```

### 5. Battle.net (optional, but recommended for current ilvls)

The roster's ilvl column prefers Blizzard data when available because
Blizzard refreshes within minutes of a character logging out, ahead of
Raider.IO's scrape cadence. Register a client at
<https://develop.battle.net/access/clients> (free; the redirect URI is
never used, just enter a valid HTTPS URL). Drop the credentials in
`.env`:
```
BLIZZARD_CLIENT_ID=<from develop.battle.net>
BLIZZARD_CLIENT_SECRET=<from develop.battle.net>
BLIZZARD_REGION=eu
```
The twice-daily `blizzard:pull` cron job populates `Snapshot::SOURCE_BLIZZARD`
rows. The roster reads Blizzard -> Wowaudit -> Raider.IO in priority order
per member. Runs as a no-op when credentials are empty, so cron stays
armed without errors. Manually:
```sh
php artisan blizzard:pull
```

### 6. GRM ingest token + sync tool on the WoW PC

Generate a 32-byte hex token:
```sh
php -r "echo bin2hex(random_bytes(32));"
```
Set it as `GRM_INGEST_TOKEN` in the Laravel `.env`.

Then on the Windows PC where you play WoW:
```cmd
cd C:\Dev\Regenesis\tools\grm-sync
setup-grm-sync.bat
```

The installer prompts for the ingest URL and the same bearer token; it
registers the `RegenesisGrmSync` scheduled task that fires every 30 minutes
and at logon. To trigger a one-off sync:
```powershell
pwsh tools\grm-sync\grm-sync.ps1 -Verbose
# or
schtasks /Run /TN RegenesisGrmSync
```

### 7. First deploy
```sh
pwsh ./deploy.ps1                    # build, push, run server-side deploy.sh
pwsh ./deploy.ps1 -DryRun            # preview only
```

The server-side `deploy.sh` runs migrations, restarts the queue worker,
and pings `/up`.

### 8. Production cron (Hostinger)
Add via hPanel cron jobs:
```cron
* * * * * /opt/alt/php84/usr/bin/php /home/u408983312/domains/regenesis.enhanceify.co.uk/laravel/artisan schedule:run >> /dev/null 2>&1
```
This drives the queue worker (jobs from `/api/ingest/grm`) and the daily
`raidhelper:sync-attendance` command.

## Useful commands

```sh
php artisan test                                # 45 Pest tests, ~2s
php artisan raidhelper:sync-attendance          # one-shot attendance pull
php artisan wowaudit:pull                       # one-shot wowaudit snapshot
php artisan tinker
  > App\Models\Member::active()->count()
  > App\Models\Snapshot::latest()->first()
  > App\Models\MemberEvent::ofType('joined')->where('occurred_at', '>=', now()->subDays(7))->count()
```

## Repo layout

```
app/
  Http/
    Controllers/{Auth,Calendar,Dashboard,Events,Ingest,Webhook}/
    Middleware/{IngestBearerToken,OfficerOnly,RaidHelperWebhookAuth}.php
  Jobs/IngestSnapshotJob.php
  Models/                                       # 11 Eloquent models
  Services/
    Calendar/IcsBuilder.php
    Discord/RoleVerifier.php
    Grm/{LuaTableParser,GrmNormalizer,GrmSnapshotDiffer,GrmTimeUtil}.php
    RaidHelper/{RaidHelperClient,EventUpserter}.php
config/{discord,grm,raidhelper}.php
database/migrations/                            # 12 migrations
resources/views/
  dashboard/widgets/                            # 11 widget partials
  events/{index,create,show}.blade.php
  layouts/dashboard.blade.php
  {landing,auth/...}.blade.php
routes/{web,api,console}.php
tools/grm-sync/                                 # PowerShell sync + Task Scheduler XML
```

## Deploy

```sh
pwsh ./deploy.ps1            # build, push, run server-side deploy.sh
pwsh ./deploy.ps1 -DryRun    # show what would happen
```

Mirrors [`enhanceify-V2/deploy.ps1`](../enhanceify-V2/deploy.ps1): same SSH
host, same `git pull` + `composer install --no-dev` + `artisan migrate
--force` + `optimize` + `queue:restart` flow on the server side.
