---
waiting_on: Hostinger, recheck 2026-08-11
---

# Confirm Hostinger restored INSERT and UPDATE on the database

## What I need from you

**Try logging in to the live site and say whether it works.** That single answer tells us whether
Hostinger restored the write grants. Two steps, in order:

1. Log in to the dashboard. Pass: you get in. Fail: the login itself 500s or silently bounces you,
   which means the session write is still being denied and the grants have not come back.
2. If step 1 fails, open a Hostinger ticket saying `u408983312_regenesis_wow` is back under the 3 GB
   cap and asking for `INSERT`/`UPDATE` to be restored. The runbook has the wording at
   [docs/ops-runbook.md](../../ops-runbook.md) step 5.

**Pass** is reaching the dashboard, plus a scheduled sync writing rows with no permission error in
the log. Record the date in the runbook, because criterion #3 wants a baseline for the next
incident.

**Fail** is the login 500ing or silently bouncing you, which means the session write is still being
denied. That is step 2's ticket, and the runbook has the wording.

**Why it needs you** The first needs a real login session and the second needs the hosting account,
so neither is reachable from here. Nothing in the app reports whether the grants are back, which is
why this has sat open since 8 July: it is possible the site has been quietly unable to sync for a
month.

## Why
On 2026-07-08 the database hit Hostinger's 3 GB per-database cap and the host auto-revoked
`INSERT`/`UPDATE`. With sessions on the `database` driver, every request's session write was denied
and the whole site returned 500. Sessions and cache were moved to the `file` driver and the
snapshot tables were truncated back under the cap, so public pages serve.

**Logins and every sync job stay blocked until the grants come back**, and nothing in the app
reports whether they have. The planning doc recorded this as a watch item on 8 July and it has been
open ever since, which means it is possible the site has been quietly unable to sync for a month.

## Not this card
The four headroom follow-ups in the runbook. Those are cards 0002 to 0004 and they reduce the
chance of a next time; this one is about whether the last time is actually over.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a user logs in, THE APP SHALL create a session and reach the dashboard.
- [ ] #2 WHEN a sync job runs, THE APP SHALL write its rows without a permission error in the log.
- [ ] #3 THE RUNBOOK SHALL record the date the grants were confirmed restored, so the next incident
      has a baseline.
<!-- AC:END -->

## Tasks
- [ ] Try a login
- [ ] Check the log for write-permission errors from the scheduled syncs
- [ ] Raise the ticket if either fails, and record the outcome here

## Decided
<!-- The answer, dated. Appended: a reversal is a later line, not an edit. -->

**2026-08-15** Confirmed. logging in is currently blocked. On the session I had that was already logged in, I was able to read most of the site, but was unable to upload a new sync file: Saving the snapshot failed: SQLSTATE[42000]: Syntax error or access violation: 1142 INSERT command denied to user 'u408983312_regen'@'127.0.0.1' for table `u408983312_regenesis_wow`.`snapshots` (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: u408983312_regenesis_wow, SQL: insert into `snapshots` (`guild_key`, `source`, `captured_at`, `payload_hash`, `raw_path`, `grm_version`, `updated_at`, `created_at`) values (Regenesis-Silvermoon, grm, 2026-08-15 12:03:50, bf5d5183c5a9e345cc2e88cf8ca967a8a277fc54a84f112d1deb7330c9be5bf4, snapshots/Regenesis-Silvermoon/01M02MX4Z4ZXCX1P5Y81XE5SMV.json.gz, ?, 2026-08-15 12:03:51, 2026-08-15 12:03:51)) Historically this issue has been 100% because the database was over 3GB (expected operating size is under 1gb) meaning that we are not truncating data properly.
