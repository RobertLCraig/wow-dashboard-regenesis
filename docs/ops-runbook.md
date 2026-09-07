# Ops runbook

Operational reference for the production site (`regenesis.enhanceify.co.uk`,
Hostinger shared hosting, account `u408983312`). Deploy mechanics live in
[README.md](../README.md#deploy) and `deploy.ps1` / `deploy.sh`.

## Hostinger hard limits worth knowing

- **MySQL: 3 GB per database.** When a database crosses 3072 MB, Hostinger
  **auto-revokes `INSERT`/`UPDATE`/`CREATE`/`INDEX`** on it (leaving
  `SELECT`/`DELETE`/`DROP`/`ALTER` so you can clear space). Write access is
  restored automatically once the DB is genuinely back under the cap; that
  fired within a day on 2026-07-09. If it hasn't fired, you are almost
  certainly still over — see the two-meters trap below — so re-measure before
  raising a ticket.
- **Hostinger meters the files on disk, not the rows.** These are different
  numbers and the gap can be gigabytes:

  | Measure | How | What it reads |
  |---|---|---|
  | rows in use | `php artisan db:show` (`data_length + index_length`) | **not** what Hostinger meters |
  | files on disk | hPanel's database list, or add `data_free` (below) | what Hostinger meters |

  `DELETE` empties InnoDB pages but never shrinks the `.ibd` file, so pruning
  drops the first number and leaves the second untouched. On 2026-08-19 the
  same database read 507 MB by rows and 3087 MB in hPanel, and six weeks of
  nightly pruning had moved the meter by nothing. `ALTER TABLE <t> FORCE`
  rebuilds the file and closes the gap — and it works on the reduced
  over-quota grant set, so a `DELETE` + rebuild is always available (see
  2026-09-07 below). **Always read hPanel, or:**
  ```sh
  php artisan tinker --execute='echo DB::select("SELECT ROUND(SUM(data_length+index_length+data_free)/1048576,1) AS mb FROM information_schema.tables WHERE table_schema=DATABASE()")[0]->mb;'
  ```
- **PHP: 30s wall clock**, no Node/Lua. Syncs run as batched cron jobs.
- **MySQL: `MAX_STATEMENT_TIME 120s`** on the app DB user.

---

## Incident: site-wide 500, DB write-locked (2026-07-08)

### Symptom
Every page — including the static landing page — returned HTTP 500.

### Root cause
`storage/logs/laravel.log` showed:

```
SQLSTATE[42000]: 1142 UPDATE command denied to user 'u408983312_regen'@'127.0.0.1'
for table `u408983312_regenesis_wow`.`sessions`
```

The DB had grown to **3072 MB** (dead on the 3 GB cap), so Hostinger revoked
writes. Because sessions were on the **database** driver, the `StartSession`
middleware writes a session row on *every* request; that write was denied, so
every route 500'd — even routes that touch no data.

The bloat was **unpruned append-only snapshot tables** — a fresh fat JSON row
per member per source pull, never swept:

| Table | Size | Rows |
|---|---|---|
| `member_equipment_snapshots` | 1.38 GB | 32,529 |
| `member_snapshots` | 1.28 GB | 92,202 |
| `member_raid_snapshots` | 0.25 GB | 6,868 |
| `member_social_snapshots` | 0.13 GB | 481 |

### Fix applied
1. **Restore availability (non-destructive):** set `SESSION_DRIVER=file` and
   `CACHE_STORE=file` in prod `.env`, `php artisan config:clear && config:cache`.
   Homepage back to 200 immediately, no data touched.
2. **Get under quota:** `TRUNCATE TABLE member_equipment_snapshots;` (only the
   `DROP` privilege is needed, which we still had). DB dropped 3072 → 1695 MB.
   Gear-snapshot *history* lost; the latest gear repopulates on the next
   `blizzard:pull-equipment` sweep once writes return. No widget reads gear
   history (BiS/gear-health read the latest row only).
3. **Prevent recurrence:** added `snapshots:prune` (see below) + moved
   sessions/cache off the DB permanently.

> Note: a plain `DELETE` would **not** have helped on its own — InnoDB keeps
> the freed pages, so `information_schema` size (what Hostinger meters) doesn't
> drop without a `TRUNCATE`/rebuild. `TRUNCATE` was believed at the time to be
> the only in-DB lever; **that was wrong**, and 2026-09-07 proved it. See below.

---

## Incident: cap re-tripped, fixed without losing anything (2026-09-07)

### Symptom
Hostinger emailed "Database size limit has been reached". Site stayed up
(HTTP 200 — sessions are on `file` since July), but every sync command threw
`1142 INSERT command denied`. `SHOW GRANTS` confirmed
`INSERT`/`UPDATE`/`CREATE`/`INDEX` were gone again.

### Root cause — not a bug
`snapshots:prune` was running nightly and working correctly. Nothing older
than the 30-day window survived. **30 days of history was simply bigger than
3 GB.** The write rate had climbed to ~8,700 `member_snapshots` rows a day, so
the retained set was 185,559 rows / 2226 MB in that one table, 3066 MB in
total. The steady-state estimate in `config/snapshots.php` (~1.3 GB) was
wrong by more than 2x.

Diagnostic that settles "is the prune broken or is the window too big":
```sh
php artisan snapshots:prune --dry-run     # 0 to prune == the prune is fine
```

### Fix applied — no truncate, no data loss
1. **`ALTER TABLE member_events FORCE` on a tiny table first, to test the
   privilege.** It succeeded. This is the finding that changes the runbook:
   an InnoDB rebuild goes through on the reduced over-quota grant set
   (`ALTER` + `DROP` are left behind), so **`TRUNCATE` is not the only lever
   and history never has to be thrown away.** `OPTIMIZE TABLE` is what the old
   runbook reached for and it is the one that needs grants we don't have.
2. Cut the window: `SNAPSHOT_RETENTION_DAYS=7` in prod `.env`,
   `config:clear && config:cache`.
3. `php artisan snapshots:prune` → 138,025 rows deleted in 3.7s.
4. `ALTER TABLE <t> FORCE` over the five snapshot tables plus `snapshots`,
   to hand the freed pages back to the meter.

Result: **3066 MB → 1263 MB**, 1.8 GB of headroom, every screen unaffected
(the prune always protects each member's latest row per source).

---

## Runbook: "site is 500ing"

1. **Read the real error** (don't guess — the 500 page is generic):
   ```sh
   ssh -p 65002 u408983312@141.136.33.219 \
     'tail -120 /home/u408983312/domains/regenesis.enhanceify.co.uk/laravel/storage/logs/laravel.log'
   ```
2. If it's a **`1142 ... command denied`** on writes → the write grants are
   gone. Get the size and the grants (both read creds straight from `.env`, so
   no password ends up on the command line):
   ```sh
   # in the laravel dir
   php artisan db:show                      # "Total Size" + per-table sizes
   php artisan tinker --execute='foreach(DB::select("SHOW GRANTS FOR CURRENT_USER()") as $r){ echo implode("|",(array)$r),PHP_EOL; }'
   ```
   Missing `INSERT`/`UPDATE` in the grants confirms the lock. **Then read the
   size, because it splits the fix in two:**
   - **Over ~3072 MB** → the cap tripped it. Go to step 3 and reclaim space.
   - **Comfortably under** → the cap is not the problem and clearing more data
     will not help. Hostinger's auto-restore has stuck. Go straight to step 5.
3. **Check whether the prune is broken or the window is just too big:**
   ```sh
   php artisan snapshots:prune --dry-run
   ```
   "would prune 0" means the prune is healthy and the retention window no
   longer fits the write rate. Shrink it (`SNAPSHOT_RETENTION_DAYS` in prod
   `.env`, then `config:clear && config:cache`) rather than hunting a bug.
4. **Reclaim space — delete, then rebuild. Both work while over quota.**
   ```sh
   php artisan snapshots:prune            # DELETE is still granted
   # then hand the freed pages back to the meter, one table at a time:
   php artisan tinker --execute="DB::statement('ALTER TABLE member_snapshots FORCE');"
   ```
   `ALTER TABLE ... FORCE` rebuilds the `.ibd` and needs only `ALTER`, which
   survives the revocation. Do **not** reach for `OPTIMIZE TABLE` /
   `mysqlcheck -o` here — those need `INSERT`/`CREATE`, which are exactly what
   is gone. Truncating a table is a last resort and is no longer necessary.
5. **Open a Hostinger ticket** only if a day has passed with the *on-disk*
   figure genuinely under the cap and the grants are still missing. Quote the
   hPanel figure, not the row figure:
   *"Database `u408983312_regenesis_wow` had `INSERT`/`UPDATE`/`CREATE`/`INDEX`
   revoked when it went over the 3 GB limit. It is now NNN MB in hPanel, well
   under the limit, and `SHOW GRANTS` for `u408983312_regen`@`127.0.0.1` still
   omits them. Please restore `INSERT`, `UPDATE`, `CREATE` and `INDEX`."*

### Grant restoration log

The date writes were last confirmed working, so the next incident has a
baseline for how long a restore takes.

| Revoked | Confirmed restored | Note |
|---|---|---|
| 2026-07-08 | 2026-07-09 | auto-restored within a day of the truncate |
| ~2026-07-10 | **pending** | syncs refilled the tables and re-tripped the cap within a day; truncated again 2026-08-19, 3234 → 1468 MB (board card 0001) |
| ~2026-09-06 | **pending** | 30-day window outgrew the cap; window cut to 7 days + rebuild, 3066 → 1263 MB on 2026-09-07, no data thrown away |

---

## Prevention (shipped)

- **`snapshots:prune`** (daily, `routes/console.php`; config `config/snapshots.php`,
  env `SNAPSHOT_RETENTION_DAYS`, default 7 since 2026-09-07 — 30 did not fit).
  Deletes snapshot rows older than
  the window but **always keeps each member's latest row per source**, so the
  current-state UI is never affected (churn/anniversary history lives in the
  separate, tiny `member_events` table). **It bounds the row count, not the
  file size** — it deletes, and `DELETE` never returns pages to Hostinger's
  meter. Treat it as protection against unbounded *growth*; to move the meter
  it has to be followed by `ALTER TABLE <t> FORCE` on each pruned table.
- **Sessions + cache on `file`**, not `database` — a DB-write outage no longer
  takes the whole site down; public pages stay served.
- **DB-size line in the weekly digest** (`WeeklyDigestBuilder`, probe in
  `App\Services\Digest\DatabaseSize`). Every digest carries the total against
  the cap; past the threshold it carries a warning plus the five largest tables.
  It measures `data_length + index_length + data_free` — the on-disk figure the
  host meters, not the row figure. Cap and threshold are config because both
  belong to the hosting plan: `DIGEST_DB_CAP_MB` (3072) and `DIGEST_DB_WARN_AT`
  (0.8) in `config/digest.php`. Nothing is printed on sqlite; there is no
  `information_schema` to read.

## Follow-ups (not yet done — further headroom, in priority order)

1. **Dedup-on-write for `member_equipment_snapshots`.** Store a per-member
   content hash and skip writing a new 43 KB gear blob when a member's gear is
   unchanged since their last row (gear changes rarely). The current
   `snapshots.payload_hash` dedup is at the wrong granularity — it hashes the
   whole batch, and the batches rotate (100 stalest members/run) so it almost
   never matches. Needs care: `EquipmentSnapshotImporter::selectMembersToFetch`
   orders by `captured_at`, so a skipped write must still record "checked" or
   the member is re-selected every run.
2. **Thin `member_snapshots.raw_json`** (14 KB/row). **Do not drop the column.**
   A grep on 2026-08-29 (board card 0004) found four live readers, one of them
   a widget, which is more than the earlier note here claimed:

   | Reader | Reads | Which row |
   |---|---|---|
   | `resources/views/components/weekly-key-cell.blade.php:21` | `mythic_plus_weekly_highest_level_runs` — the M+ weekly-key popover on the roster, keynight and character screens | latest RIO |
   | `App\Services\Bis\BisComparisonService::rawArray` | gear fallback | latest RIO |
   | `App\Services\Grm\GrmSnapshotDiffer::diffMemberSnapshots` | `note` / `officerNote` / `customNote.3`, to emit note-changed events | previous GRM |
   | `App\Console\Commands\BackfillMplusRuns` | historical M+ runs | **every** row, by design |

   Only the first three are "latest row" reads. `BackfillMplusRuns` is a
   recovery command that walks the whole history, so nulling old rows retires
   it. That is the trade-off to settle before writing any migration.
3. **One-off `OPTIMIZE TABLE`** on `member_snapshots` (and others) after the
   first prune, once write grants are back, to return the freed InnoDB pages to
   the size meter.
