---
not_for_the_loop: the fix is a new key in the live site's .env and a check run on the live host
---
# wowaudit stopped accepting our key on 8 July, so the vault and M+ widgets are three months stale

## What I need from you

**One action.**

1. Make a new wowaudit API key and put it in 1Password, vault **Claude**, as an item titled `wowaudit api key` with the key in its password field.
   - Get it at <https://wowaudit.com>, sign in, open the Regenesis team, then **Settings**, then **API**, and press the button that creates a new key.
   - Pass: the item exists in vault Claude and its password is a 64-character string.
   - Fail: wowaudit has no API page for the team, or says the team has no API access. Say what the page shows in this card and stop. That would mean the subscription lapsed, which is a cost call for you.

Paste to answer: `**2026-10-13** **Decided:** new key is in 1Password Claude as "wowaudit api key".`

**Why it needs you.** Only someone signed in to wowaudit as a team admin can make a key. Nothing on this machine holds a working one: the key on the live site and the one in your own `.env` are both refused.

**What's wrong.** The dashboard's Great Vault progress and Mythic+ widgets have not updated since 8 July. Nothing on screen says so.

**Cause.** wowaudit answers every request with `401 unauthorized`. The key was revoked or replaced on wowaudit's side, most likely on 8 July, the same day as the database outage.

**Recommendation.** Make the key and drop it in 1Password. An attended agent then does the rest (see For the agent). You type nothing else.

---
## For the agent (Rob can stop reading here)

## Why

**What is wrong now.** `wowaudit:pull` runs hourly on production and exits 1 every time. Measured over SSH, read-only, on 2026-10-13:

- `storage/logs/laravel.log`: 2,073 `Scheduled command [... wowaudit:pull] failed with exit code [1]` lines. First `2026-07-08 18:00:04`, last `2026-10-13 16:00:02`. 24 a day every day since, 41 in the last two days.
- That is the **only** message. No other wowaudit text has been logged since `2026-07-08 21:00:09` (a DEBUG "no GRM match" line). `cron.log` has the same exit-1 line and nothing more.
- A read-only probe through `WowauditClient::fromConfig()` on the live host: key configured, 64 characters, `GET /period` and `GET /team` both return `401 {"error":"unauthorized","message":"ApplicationController::UnauthorizedException"}`. The local `.env` key is also 64 characters and also gets 401.

**What it costs.** The Great Vault and M+ widgets read the last wowaudit snapshot, from 8 July. Wowaudit is also the second source for item level, after Blizzard. Officers see stale numbers with no warning.

**How it came to be this way.** `PullWowauditSnapshot::handle` catches the exception and prints it with `$this->error()`, which goes to the command's stdout. The scheduler throws stdout away and logs only the exit code, so three months of 401s were logged as a bare "exit code 1" and nobody could tell a dead key from a dead API.

## Not this card

- Retrying, backoff or alerting for wowaudit. Logging the reason is enough to diagnose it next time.
- The other integrations' commands, even if they swallow errors the same way. Raise a card for each one you find.
- The "no GRM match" DEBUG lines.

## Acceptance

<!-- AC:BEGIN -->
- [ ] WHEN `wowaudit:pull` catches an exception, THE APP SHALL write its message to the Laravel log at error level, not only to the console. proves: `it logs the reason when the wowaudit pull fails`
- [ ] WHEN wowaudit answers `/period` with 401, THE APP SHALL log a message that says the key was rejected and names the status. proves: `it says the key was rejected when wowaudit returns 401`
- [ ] WHEN the new key is in the live `.env`, THE APP SHALL run `php artisan wowaudit:pull` on the live host and exit 0 with a `Period N: ... Snapshot #N` line. proves: manual
<!-- AC:END -->

## Tasks

- [ ] Log the caught exception in `PullWowauditSnapshot::handle` (one `Log::error` with the message).
- [ ] Make the 401 message name the key in `WowauditSnapshotImporter::pullCurrentPeriod`.
- [ ] Write the two tests above, faking the HTTP client with `Http::fake`.
- [ ] Deploy (card 0036's route: `pwsh ./deploy.ps1`), then set the new key on the live host.

## Plan

Stand in `C:\Dev\Regenesis`, on a card branch. The code half can be built before Rob answers.

1. Code and tests as above. Run `.\vendor\bin\pest.bat` and `.\vendor\bin\pint.bat --test`.
2. After Rob's answer, in an attended session: read the key with `op read "op://Claude/wowaudit api key/password"`. Never print it.
3. Set it on the live host in `/home/u408983312/domains/regenesis.enhanceify.co.uk/laravel/.env` (SSH: `ssh -p 65002 u408983312@141.136.33.219`), replacing the `WOWAUDIT_API_KEY=` line. Then `php artisan config:cache`, because `deploy.sh` runs `optimize` and config is cached.
4. Run `/opt/alt/php84/usr/bin/php artisan wowaudit:pull` there. Pass: exit 0 and a `Period N` line. Then check the next hourly run in `storage/logs/cron.log` shows `finished` with no `failed` line after it.
5. Do not edit Rob's local `.env` (HANDOVER: never edit `.env`). Tell him the local key is dead too.

## Comments

**2026-10-13** Raised from a production log read. Evidence above was measured over SSH, read-only; the only remote action was two GET requests to wowaudit through the app's own client. Nothing on the server was changed. (agent)
