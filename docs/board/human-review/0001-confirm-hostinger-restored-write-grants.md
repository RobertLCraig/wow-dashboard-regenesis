---
not_for_the_loop: raising the ticket needs the Hostinger hosting account login
---

# Confirm Hostinger restored INSERT and UPDATE on the database

## What I need from you

**Open a Hostinger support ticket and paste this in.** That is the only step left. Nothing else
unblocks the site.

> Hello. Database `u408983312_regenesis_wow` had `INSERT`, `UPDATE`, `CREATE` and `INDEX` revoked on
> 2026-07-08 when it went over the 3 GB limit. It has been under the limit ever since and is
> **507 MB today**, but the permissions have not come back on their own in six weeks.
> `SHOW GRANTS` for `u408983312_regen`@`127.0.0.1` still returns only SELECT, DELETE, DROP,
> REFERENCES, ALTER, CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE, CREATE VIEW, SHOW VIEW, CREATE
> ROUTINE, ALTER ROUTINE, EVENT, TRIGGER, DELETE HISTORY, SHOW CREATE ROUTINE.
> Please restore `INSERT`, `UPDATE`, `CREATE` and `INDEX`.

**Pass** is both of:
- you can log in to the dashboard
- the next morning's cron writes rows with no `1142` error in `storage/logs/laravel.log`

**Fail** is Hostinger replying that the database is over quota. It is not. The numbers above came off
the live server today, so send them the numbers again.

**Why it needs you** Raising the ticket needs the hosting account login, which is not reachable from
here.

## The size theory is wrong, and that changes the ask

You said this is always the 3 GB cap, and that we are not clearing old data properly. Checked on the
live server on 2026-08-19:

- The database is **507 MB**. The cap is 3072 MB. It sits at one sixth of the cap.
- It was 1695 MB after the July clear-out, so the nightly `snapshots:prune` job has taken another
  1188 MB off since. The clearing works.
- Writes are still denied. The cron job failed again at 04:30 on 2026-08-18 with
  `1142 INSERT command denied` on `attendance_stats`.

So size is not the cause any more. Hostinger is meant to switch the permissions back on by itself
once the database drops under the cap. It did not. That is a stuck switch on their side, and only
they can flip it.

## Why
On 2026-07-08 the database hit the 3 GB cap and Hostinger auto-revoked the write permissions.
Sessions were on the `database` driver, so every request tried to write a session row, every write
was denied, and the whole site returned 500. Sessions and cache were moved to `file` and the fat
snapshot tables were cleared, so public pages serve again.

**Logins and every sync job stay blocked until the permissions come back**, and nothing in the app
reports whether they have. That is why this sat open since 8 July: the site has been quietly unable
to sync for six weeks.

## Not this card
The headroom follow-ups in the runbook, cards 0002 to 0004. They lower the chance of a next time.
This one is about whether the last time is over.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a user logs in, THE APP SHALL create a session and reach the dashboard.
- [ ] #2 WHEN a sync job runs, THE APP SHALL write its rows without a permission error in the log.
- [ ] #3 THE RUNBOOK SHALL record the date the grants were confirmed restored, so the next incident
      has a baseline.
<!-- AC:END -->

## Tasks
- [x] Try a login
- [x] Check the log for write-permission errors from the scheduled syncs
- [ ] Raise the ticket, and record the outcome here

## Decided
<!-- The answer, dated. Appended: a reversal is a later line, not an edit. -->

**2026-08-15** Confirmed. logging in is currently blocked. On the session I had that was already logged in, I was able to read most of the site, but was unable to upload a new sync file: Saving the snapshot failed: SQLSTATE[42000]: Syntax error or access violation: 1142 INSERT command denied to user 'u408983312_regen'@'127.0.0.1' for table `u408983312_regenesis_wow`.`snapshots` (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: u408983312_regenesis_wow, SQL: insert into `snapshots` (`guild_key`, `source`, `captured_at`, `payload_hash`, `raw_path`, `grm_version`, `updated_at`, `created_at`) values (Regenesis-Silvermoon, grm, 2026-08-15 12:03:50, bf5d5183c5a9e345cc2e88cf8ca967a8a277fc54a84f112d1deb7330c9be5bf4, snapshots/Regenesis-Silvermoon/01M02MX4Z4ZXCX1P5Y81XE5SMV.json.gz, ?, 2026-08-15 12:03:51, 2026-08-15 12:03:51)) Historically this issue has been 100% because the database was over 3GB (expected operating size is under 1gb) meaning that we are not truncating data properly.
