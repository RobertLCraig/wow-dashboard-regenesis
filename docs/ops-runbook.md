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
  nightly pruning had moved the meter by nothing. **Always read hPanel, or:**
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

> Note: a plain `DELETE` would **not** have helped — InnoDB keeps the freed
> pages, so `information_schema` size (what Hostinger meters) doesn't drop
> without a `TRUNCATE`/`OPTIMIZE`/rebuild, and rebuild needs `CREATE`/`INSERT`
> (revoked while over quota). `TRUNCATE` was the only in-DB lever available.

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
3. **Reclaim space.** With write grants restored (or via `TRUNCATE`/`DROP`,
   which only need `DROP`): the snapshot child tables are the usual culprit.
   Fastest safe move is truncating `member_equipment_snapshots` (no history is
   read from it). Never truncate `member_snapshots` blindly — it holds the
   latest per-member state the dashboard reads.
4. **After writes return, reclaim the freed pages** (one-off, needs write
   grants) so the file size actually shrinks, not just the row count:
   ```sh
   php artisan snapshots:prune            # delete aged-out rows first
   mysqlcheck -o -u"$DB_USERNAME" -h127.0.0.1 "$DB_DATABASE" member_snapshots member_raid_snapshots
   # (OPTIMIZE TABLE rebuilds the .ibd and returns space to the meter)
   ```
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

---

## Prevention (shipped)

- **`snapshots:prune`** (daily, `routes/console.php`; config `config/snapshots.php`,
  env `SNAPSHOT_RETENTION_DAYS`, default 30). Deletes snapshot rows older than
  the window but **always keeps each member's latest row per source**, so the
  current-state UI is never affected (churn/anniversary history lives in the
  separate, tiny `member_events` table). **It bounds the row count, not the
  file size** — it deletes, and `DELETE` never returns pages to Hostinger's
  meter. Treat it as protection against unbounded *growth*, not as a way back
  under the cap. Only `TRUNCATE` does that.
- **Sessions + cache on `file`**, not `database` — a DB-write outage no longer
  takes the whole site down; public pages stay served.

## Follow-ups (not yet done — further headroom, in priority order)

1. **Dedup-on-write for `member_equipment_snapshots`.** Store a per-member
   content hash and skip writing a new 43 KB gear blob when a member's gear is
   unchanged since their last row (gear changes rarely). The current
   `snapshots.payload_hash` dedup is at the wrong granularity — it hashes the
   whole batch, and the batches rotate (100 stalest members/run) so it almost
   never matches. Needs care: `EquipmentSnapshotImporter::selectMembersToFetch`
   orders by `captured_at`, so a skipped write must still record "checked" or
   the member is re-selected every run.
2. **Stop persisting `member_snapshots.raw_json`** (14 KB/row of debug ballast)
   or keep it only on each member's latest row. It's read only for the latest
   RIO row (BiS gear fallback, `BisComparisonService`) and the previous GRM row
   (diffing, `GrmSnapshotDiffer`) — never on old rows.
3. **One-off `OPTIMIZE TABLE`** on `member_snapshots` (and others) after the
   first prune, once write grants are back, to return the freed InnoDB pages to
   the size meter.
4. **DB-size alert** in the weekly digest (warn at, say, 2.4 GB / 80% of cap)
   so the next approach to the ceiling is visible before it bites.
