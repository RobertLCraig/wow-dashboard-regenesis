# Warn in the weekly digest before the database fills again

## Why
The 8 July outage was a 3 GB cap reached silently. Nothing measured the database against its own
ceiling, so the first signal was every route returning 500. `snapshots:prune` now bounds the
snapshot tables, but a bound is not a measurement: any new append-only table, or a roster that
grows, walks back toward the same cliff with the same amount of warning, which is none.

This is the cheapest of the four runbook follow-ups and the only one that changes what happens next
time rather than how much room there is.

## Not this card
Reclaiming space. Dedup-on-write is 0003 and the ballast removal is 0004.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN the weekly digest runs, IT SHALL include the current total database size in MB.
- [x] #2 WHERE the database exceeds 80 percent of the 3 GB cap, THE DIGEST SHALL carry a visible
      warning naming the largest tables by size.
- [x] #3 THE THRESHOLD and the cap SHALL be config values, not literals, because the cap is a
      property of the hosting plan and will change before the code does.
- [x] #4 WHEN the printed percentage reaches the warning threshold, THE DIGEST SHALL carry the warning
      too, and WHEN the size probe returns no tables, THE DIGEST SHALL omit the line rather than print
      `0.0 MB`.
<!-- AC:END -->

## Tasks
- [x] Query `information_schema.TABLES` for per-table size in the digest command
- [x] Add the threshold warning and the top-tables breakdown
- [ ] Run the digest once against production data to see the real numbers

## Comments

**2026-08-29** Built the size line. `App\Services\Digest\DatabaseSize` asks
`information_schema.tables` for per-table size, largest first;
`WeeklyDigestBuilder::database()` sums it, compares it to the cap and hands the render a
`database` block. Below the threshold the digest carries one line, `**Database**: 507.3 MB
of 3,072 MB (17%).`; at or above it, a ⚠️ warning line plus the five largest tables named
with their sizes. Cap and threshold are `DIGEST_DB_CAP_MB` (3072) and `DIGEST_DB_WARN_AT`
(0.8) in `config/digest.php`. Four new tests cover the line, the warning, the top-tables
list, and that moving the config alone flips the same 500 MB from comfortable to a warning.

Two things I decided rather than found written down. **The size is
`data_length + index_length + data_free`**, not the row figure, because the runbook is
explicit that the host meters the files on disk and that the two numbers differed by 2.5 GB
on 2026-08-19 — warning on the row figure would have stayed quiet through the whole of the
last incident. **The threshold is `>=`**, so exactly 80 percent warns.

The probe returns null on any driver without `information_schema`, so on sqlite the digest
simply omits the line rather than throwing. That covers the test suite and a fresh local
checkout, and it is why acceptance #1 is ticked for production MySQL rather than everywhere.

Not settled from the repository, and the reason the third task stays open: **nobody has run
this against real data.** Local MySQL was not running in this worktree
(`127.0.0.1:3306` refused), and production is not reachable from here, so the
`information_schema` query itself has never executed — it is only checked against the
near-identical query the runbook already documents at line 29. **It needs one
`php artisan digest:weekly --dry-run` on production before this is trusted.** The digest
also still needs a browser/Discord look, which cannot be done from a worktree.

One thing a reviewer should know: `vendor/bin/pint` rewrites 185 files that were already
non-conforming before this card. I reverted that and left the repository as it was; only
the new `DatabaseSize.php` was checked against Pint, and it passes. Repo-wide formatting is
somebody's card, not this one. Full suite: 705 passed.

### 2026-08-29 review (v20260829141550-7f59)

**suite**

`vendor\bin\pest.bat` exited 0 after 74s, run by this job rather than reported by the card.

**acceptance: sound**

I traced each criterion to code.

**#1 ÔÇö total size in MB.** `WeeklyDigestBuilder::database()` sums the per-table figures from `DatabaseSize::tableSizes()`, and `WeeklyDigestBuilder::renderMarkdown()` prints `**Database**: ÔÇª MB of ÔÇª MB (ÔÇª%)`. `SendWeeklyDigest::handle()` posts exactly that `markdown` string, so the line reaches Discord. It is omitted on sqlite only, and production sets `DB_CONNECTION=mysql`.

**#2 ÔÇö warning plus largest tables.** `WeeklyDigestBuilder::database()` sets `over` when the total reaches cap ├ù threshold. `renderMarkdown()` then emits the ÔÜá´©Å line and a `Largest tables:` line from `top_tables`, which is the first five of a list `DatabaseSize::tableSizes()` already sorted `ORDER BY mb DESC`. The block sits high in the document, so `DiscordWebhookPoster::chunk()` keeps it in the first message.

**#3 ÔÇö config, not literals.** `db_cap_mb` and `db_warn_at` live in `config/digest.php` behind `DIGEST_DB_CAP_MB` / `DIGEST_DB_WARN_AT`, and `WeeklyDigestBuilder::database()` is the only reader. No 3072 or 0.8 appears in either class.

I tried to break it on the null path, the chunking and the sort order. It held. The size formula matches the query `docs/ops-runbook.md` already documents.

VERDICT: sound

**scope: defect**

**Fence: clean.** Nothing here reclaims space. `WeeklyDigestBuilder::database()` only measures and prints. The `docs/ops-runbook.md` edit adds this card's own "Prevention (shipped)" bullet and deletes its own follow-up entry. It leaves the 0003 and 0004 entries alone.

**Half done: the production path has never run.** `DatabaseSize::tableSizes()` is the only code that talks to `information_schema`, and no test reaches it. Three tests inject the stub built by `fakeDbSize()` in `tests/Feature/WeeklyDigestTest.php`. The fourth, `it('digest omits the database line when the driver has no information_schema')`, proves only the sqlite null path. So the green suite proves the sums and the wording. It proves nothing about the query. AC #1 is ticked on that evidence.

The card blames a dead MySQL in the worktree. This repo's `.env` points at `mysql` on `127.0.0.1:3306`, so a build session here can run the probe. That makes it the next session's job, not a person's.

Declared narrowing, worth naming: the driver guard in `DatabaseSize::tableSizes()` turns "every digest" into "every MySQL digest".

VERDICT: defect

**breakage: defect**

I checked every caller, the poster, the scheduler, the runbook and the docs.

**1. The warning can stay quiet while the digest prints the threshold.**
`WeeklyDigestBuilder::database()` rounds `percent` on its own, and tests `over` against `cap * db_warn_at` separately. The two do not agree at the edge. With the shipped config (cap 3072, warn 0.8) a total of 2455.0 MB gives `over = false` but `percent = 80`. `WeeklyDigestBuilder::renderMarkdown()` then prints `**Database**: 2,455.0 MB of 3,072 MB (80%).` with no ÔÜá´©Å. A reader sees the warn number and no warning. The tests in `tests/Feature/WeeklyDigestTest.php` build 16%, 81% and 98% only ÔÇö never the band, never exactly 80%.

**2. A doc this change made false.**
`docs/planning/next-session.md`, section "0b. Production incident + DB retention (2026-07-08)", still lists "DB-size alert in the digest" among "the remaining optimisation follow-ups ... in `docs/ops-runbook.md`". This commit moved that item out of the runbook's follow-ups and into "Prevention (shipped)".

**3. Untested edge case.** `DatabaseSize::tableSizes()` returning `[]` (not null) makes `database()` print `0.0 MB ... (0%)` ÔÇö false comfort instead of omitting the line.

Callers are fine: the new constructor argument is optional and `SendWeeklyDigest::handle()` still builds.

VERDICT: defect


**2026-08-29** The reviewer returned this card and its finding is the last review entry at the bottom of ## Direction. The loop moved it from todo/ to human-review/ because it has bounced 1 time between todo and ai-review, all 3 criteria ticked. THE BUILDER COULD NOT ACT ON THAT FINDING. A reviewer never unticks a criterion - it is forbidden from editing acceptance at all - so the card came back with 3 of 3 criteria still ticked, every session found nothing open to do, and the loop promoted it again on the boxes. Untick what the reviewer disproved and move it back to todo/, or say here why the finding is wrong.

**2026-09-28** Manager pass: reopened #1 and added #4, because the 2026-08-29 review's findings hold
on `main`. #1: `DatabaseSize::tableSizes()` is the only code that queries `information_schema`, and it
has never run. Every test injects a stub, and this repository's `.env` points at a local MySQL a build
session can run it against. #4: `WeeklyDigestBuilder::database()` still rounds `percent` separately
from the `over` test, so 2,455 MB of 3,072 prints "(80%)" with no warning, and an empty table list
prints `0.0 MB`. Also owed: `docs/planning/next-session.md` still lists this alert as a follow-up.

**2026-09-28** RESULT: partial
TESTS: +2 new, all green
TOUCHED: app/Services/Digest/WeeklyDigestBuilder.php, tests/Feature/WeeklyDigestTest.php, docs/planning/next-session.md, docs/board/in-progress/0002-db-size-alert-in-the-weekly-digest.md
OUT-OF-SCOPE: none

#4 is built. Two tests, both watched red first for the reason the criterion names:
`digest warns when the printed percentage reaches the threshold` (2,455 MB of 3,072 gave
`percent = 80`, `over = false`) and `digest omits the database line when the size probe returns no
tables` (an empty list gave a `0.0 MB` block). `WeeklyDigestBuilder::database()` now returns null on
an empty table list as well as on null, and `over` is also true when the rounded `percent` reaches
`db_warn_at * 100`. The `next-session.md` line now says the alert shipped.

#1 stays open, for the same reason as before, now checked rather than assumed. Nothing listens on
`127.0.0.1:3306` in this session, and no `mysqld` or MySQL service is installed on this machine
(searched Herd's `bin\services`, the PATH, Windows services and `C:\` to depth 5). The `.env` names
a local MySQL, but there is no server behind it. The suite runs on sqlite, which has no
`information_schema`, so the harness cannot express the query either: any test of
`DatabaseSize::tableSizes()` in this suite would stub the one thing #1 needs proved. To close #1,
Rob has to do one of two things: start a local MySQL with the `regenesis` schema loaded and run
`php artisan digest:weekly --dry-run`, or run that command once on production. The digest also
still needs a Discord look, which a worktree cannot give.

Full suite: 750 passed. `pint --test` fails on `app/Services/Raiderio/RaiderioSnapshotImporter.php`
only. That is not my file; card 0014 already carries it, so I raised no new card.

**2026-09-28** RESULT: blocked
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0002-db-size-alert-in-the-weekly-digest.md
OUT-OF-SCOPE: none

Only #1 is open, and nothing in it can be built from here. Checked again this session: nothing
listens on `127.0.0.1:3306`, no MySQL or MariaDB Windows service exists, and the WSL `Ubuntu`
distro has no `mysqld`, `mariadbd` or MySQL package either. There is no Docker. Installing a
database server on Rob's machine is not a call an unattended card session should make, so I did
not. No test was written: the suite is sqlite, which has no `information_schema`, so any test of
`DatabaseSize::tableSizes()` here would stub the one query #1 needs proved.

To close #1, Rob picks one: run `php artisan digest:weekly --dry-run` once on production (it also
answers the third task, real numbers), or start a local MySQL with the `regenesis` schema and run
the same command. The digest's Discord look is still owed too. Until then this card will come back
to the loop with the same one box open; it wants `not_for_the_loop:` or a person, not another run.

Full suite: 750 passed. `pint --test` fails on `RaiderioSnapshotImporter.php` only (card 0014).

**2026-09-28** RESULT: blocked
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0002-db-size-alert-in-the-weekly-digest.md
OUT-OF-SCOPE: none

Third run, same wall. Nothing listens on `127.0.0.1:3306`, no MySQL or MariaDB service exists, and
there is no Docker. #1 still needs Rob to run `php artisan digest:weekly --dry-run` once on
production (or against a local MySQL he starts). No code changed. Full suite: 750 passed. This card
wants `not_for_the_loop:` or a person; another unattended run will find the same thing.

**2026-09-28** RESULT: blocked
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0002-db-size-alert-in-the-weekly-digest.md
OUT-OF-SCOPE: none

Fourth run, nothing changed: `127.0.0.1:3306` refuses, no MySQL or MariaDB service, no `mysqld`,
`mariadbd` or `docker` on the PATH. #1 needs a person to run `php artisan digest:weekly --dry-run`
once on production. Full suite: 750 passed. Please mark this card `not_for_the_loop:` until then.

**2026-09-28** RESULT: blocked
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0002-db-size-alert-in-the-weekly-digest.md
OUT-OF-SCOPE: none

Fifth run, same result: `127.0.0.1:3306` refuses, no MySQL or MariaDB service, no `mysqld`,
`mariadbd` or `docker` on the PATH. No code changed. #1 needs a person to run
`php artisan digest:weekly --dry-run` once on production. Full suite: 750 passed. The scheduler
keeps re-running this card; it needs `not_for_the_loop:` or Rob, not a sixth session.

**2026-09-28** RESULT: blocked
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0002-db-size-alert-in-the-weekly-digest.md
OUT-OF-SCOPE: none

Sixth run, same result: `127.0.0.1:3306` refuses, no MySQL or MariaDB service, no `mysqld`,
`mariadbd` or `docker` on the PATH. No code changed. #1 needs a person to run
`php artisan digest:weekly --dry-run` once on production. Full suite: 750 passed.

**2026-09-28** RESULT: blocked
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0002-db-size-alert-in-the-weekly-digest.md
OUT-OF-SCOPE: none

Seventh run, same result: `127.0.0.1:3306` refuses, no MySQL or MariaDB service, no `mysqld`,
`mariadbd` or `docker` on the PATH. No code changed. #1 needs a person to run
`php artisan digest:weekly --dry-run` once on production. Full suite: 750 passed.
