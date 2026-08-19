---
waiting_on: Hostinger to restore the write grants - recheck 2026-08-20
---

# Confirm Hostinger restored INSERT and UPDATE on the database

## What I need from you

**Two steps, in order.**

1. **Tomorrow, try logging in.** Pass: you reach the dashboard. That means the grants came back on
   their own and this card is done. Record the date in the runbook's grant restoration log.
2. **If it still fails, open a Hostinger ticket** with the text below. Do not wait longer than a
   day; in July the auto-restore fired within 24 hours, and after that it never fired at all.

> Hello. Database `u408983312_regenesis_wow` had `INSERT`, `UPDATE`, `CREATE` and `INDEX` revoked
> when it went over the 3 GB limit. I have cleared space and hPanel now reports it at **1424 MB**,
> well under the 3072 MB limit. `SHOW GRANTS` for `u408983312_regen`@`127.0.0.1` still omits those
> four. Please restore them.

**Fail** is Hostinger replying that the database is still over quota. Send them the hPanel figure
again: it read 3087 MB before the clear-out and 1424 MB after, both confirmed in hPanel on
2026-08-19.

**Why it needs you** Raising the ticket needs the hosting account login, which is not reachable from
here.

## What was actually wrong, and what was done

The database really was over the cap. hPanel and the database's own numbers measure different
things, and reading the wrong one sent this card the wrong way on 2026-08-19:

| Measure | Reading | What it is |
|---|---|---|
| `information_schema` | 507 MB | the rows still in use |
| hPanel | 3087 MB | the files on disk, which is what Hostinger meters |

The nightly `snapshots:prune` job deletes rows with `DELETE`. In MySQL that empties the pages but
never shrinks the file. So the prune ran for six weeks, the row count fell, and the file Hostinger
meters never moved. Only `TRUNCATE` gives the space back, and it needs just the `DROP` grant, which
we still had.

Done on 2026-08-19: truncated `member_equipment_snapshots` and `member_raid_snapshots`. On-disk went
**3234 MB to 1468 MB**. The gear and raid snapshots refill from the half-hourly sweeps once writes
return.

**This buys about two days, not a fix.** The syncs write roughly 1 GB a day, which is what refilled
the tables and re-tripped the cap on 10 July, a day after the last clear-out. Card 0003 is the
actual fix.

## Why
On 2026-07-08 the database hit the cap and Hostinger auto-revoked the write grants. Sessions were on
the `database` driver, so every request tried to write a session row, every write was denied, and
the site returned 500. Sessions and cache moved to `file`, so public pages serve.

Logins and every sync job stay blocked until the grants come back. Officers can now at least log in
and read the site: `User::save()` swallows the revoked-grant error rather than 500ing.

## Not this card
Stopping the refill. That is card 0003, and it is the one that stops this recurring.

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
- [x] Get the database back under the cap so the grants can return
- [ ] Confirm the grants are back, or raise the ticket

## Decided
<!-- The answer, dated. Appended: a reversal is a later line, not an edit. -->

**2026-08-15** Confirmed. logging in is currently blocked. On the session I had that was already logged in, I was able to read most of the site, but was unable to upload a new sync file: Saving the snapshot failed: SQLSTATE[42000]: Syntax error or access violation: 1142 INSERT command denied to user 'u408983312_regen'@'127.0.0.1' for table `u408983312_regenesis_wow`.`snapshots` (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: u408983312_regenesis_wow, SQL: insert into `snapshots` (`guild_key`, `source`, `captured_at`, `payload_hash`, `raw_path`, `grm_version`, `updated_at`, `created_at`) values (Regenesis-Silvermoon, grm, 2026-08-15 12:03:50, bf5d5183c5a9e345cc2e88cf8ca967a8a277fc54a84f112d1deb7330c9be5bf4, snapshots/Regenesis-Silvermoon/01M02MX4Z4ZXCX1P5Y81XE5SMV.json.gz, ?, 2026-08-15 12:03:51, 2026-08-15 12:03:51)) Historically this issue has been 100% because the database was over 3GB (expected operating size is under 1gb) meaning that we are not truncating data properly.

**2026-08-19** Confirmed from hPanel: 3087/3072 MB. The database was over the cap the whole time.
Truncate both the equipment and raid snapshot tables, leave the syncs running, and fix the write
rate in card 0003.
