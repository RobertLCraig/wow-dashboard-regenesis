---
not_for_the_loop: publishes the site to Hostinger
---

# Deploy main to the live site

## Why
**The live site is about two months behind main.** On 2026-10-13 the server checkout at
`/home/u408983312/domains/regenesis.enhanceify.co.uk/laravel` was at `500d88a` (2026-08-19),
219 commits behind `origin/main`. Its `DiscordWebhookController.php` has no `thread_id` in it.

**What it costs.** Card 0030 (a webhook can target a thread) is done but not live, so card 0032's
thread row cannot be saved on `/admin/webhooks` yet. Everything else built since 2026-08-19 is
not live either, for example the trial pipeline page (0034, 0035).

## Links

**Relates to**
- `0032` - its digest-thread row needs 0030's form change on the live site. That is this deploy.
- `0030` - the thread support this deploy takes live.
- `0001` - write grants on the database. The 2026-08-20 entry there says logins and saves work
  again. The deploy runs `artisan migrate --force`, so check that first.

## Not this card
Making the digest thread or adding its webhook row (that is 0032). Any code change. Fixing
anything the deploy turns up beyond rolling back: raise a card for it.

## Plan
An attended session runs this, from `C:\Dev\Regenesis`:
1. `pwsh ./deploy.ps1 -DryRun`, then `pwsh ./deploy.ps1`. It builds assets, runs the local suite,
   pushes and runs `deploy.sh` on the server (git pull, composer, migrate, optimize, queue
   restart). Three migrations are new since `500d88a`: `drop_transmogs_from_member_social_snapshots`,
   `add_attachments_to_discord_announcements_table` and `add_source_to_bis_profiles_table`.
2. On the server, `git log -1` matches local `main`.
3. Load `/admin/webhooks` and check the add form shows the thread help line.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN the deploy has run, THE LIVE CHECKOUT SHALL be at the same commit as `origin/main`.
      proves: manual (`git log -1` on the server)
- [x] #2 WHEN `/admin/webhooks` loads on the live site, THE ADD FORM SHALL accept a URL ending
      `?thread_id=<digits>`. proves: manual
<!-- AC:END -->

## Tasks
- [x] Dry run, then deploy
- [x] Confirm the server commit
- [x] Load `/admin/webhooks` and see the thread help line

## Comments
**2026-10-13** Raised during Rob's queue clear-down. 0032 was decided as option 1 (a thread), and
its answer said the thread support has to be deployed first. Checked over SSH, read only: the live
checkout is at `500d88a` and has no thread support. (agent, after Rob's 2026-10-13 clear-down)

**2026-10-13** Deployed `c1471be` (main plus the deploy's own asset-rebuild commit), backup at
`/home/u408983312/backups/regenesis/pre-deploy-500d88a-20261013-161235.sql.gz` (mysqldump
--single-transaction, 66 MB gz, mode 600, taken before the pull). Checks: the dry run showed the
right target and nothing unexpected; the local suite passed inside `deploy.ps1`; the server's
`git log -1` is `c1471be`, equal to `origin/main`; both new migrations ran in batch 14
(`add_attachments_to_discord_announcements_table`, `add_source_to_bis_profiles_table`; the transmogs
drop had already been applied); `/` and `/up` return 200; `/admin/webhooks` is routed and redirects to
Discord sign-in (302), so the app boots; no ERROR in `laravel.log` since the deploy. Thread support
is live: the server's `Admin\DiscordWebhookController` validation accepts `?thread_id=<digits>` and
the add form carries the thread help line. Not done: a signed-in page load, which needs a Discord
login. Seen but not caused by this deploy: `wowaudit:pull` failed 41 times in the last two days of
the log. (attended agent, Rob approved in the 2026-10-13 clear-down)
