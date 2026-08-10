---
waiting_on: Hostinger, recheck 2026-08-11
---

# Confirm Hostinger restored INSERT and UPDATE on the database

## What I need from you
Two checks, in order:

1. Log in to the dashboard. Pass: you get in. Fail: the login itself 500s or silently bounces you,
   which means the session write is still being denied and the grants have not come back.
2. If step 1 fails, open a Hostinger ticket saying `u408983312_regenesis_wow` is back under the 3 GB
   cap and asking for `INSERT`/`UPDATE` to be restored. The runbook has the wording at
   [docs/ops-runbook.md](../../ops-runbook.md) step 5.

An agent cannot do either: the first needs a real session and the second needs the hosting account.

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
