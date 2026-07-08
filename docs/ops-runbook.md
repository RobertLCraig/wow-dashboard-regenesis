# Ops runbook

Operational reference for the production site (`regenesis.enhanceify.co.uk`,
Hostinger shared hosting, account `u408983312`). Deploy mechanics live in
[README.md](../README.md#deploy) and `deploy.ps1` / `deploy.sh`.

## Hostinger hard limits worth knowing

- **MySQL: 3 GB per database.** When a database crosses 3072 MB, Hostinger
  **auto-revokes `INSERT`/`UPDATE`/`CREATE`/`INDEX`** on it (leaving
  `SELECT`/`DELETE`/`DROP`/`ALTER` so you can clear space). Write access is
  restored automatically once the DB is back under the cap, though the
  re-check can lag by up to a few hours — a support ticket expedites it.
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
2. If it's a **`1142 ... command denied`** on writes → the DB is over the 3 GB
   cap. Confirm size + grants:
   ```sh
   # in the laravel dir, creds are in .env (DB_USERNAME / DB_PASSWORD / DB_DATABASE)
   mysql -u"$DB_USERNAME" -h127.0.0.1 "$DB_DATABASE" -N -e \
     "SELECT ROUND(SUM(data_length+index_length)/1024/1024,1) FROM information_schema.tables WHERE table_schema=DATABASE();"
   mysql -u"$DB_USERNAME" -h127.0.0.1 -N -e "SHOW GRANTS FOR CURRENT_USER();"
   ```
   Missing `INSERT`/`UPDATE` in the grants confirms the quota lock.
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
5. If grants haven't auto-restored within a few hours of being under the cap,
   open a Hostinger ticket: *"database `u408983312_regenesis_wow` is back under
   the 3 GB limit, please restore write access."*

---

## Prevention (shipped)

- **`snapshots:prune`** (daily, `routes/console.php`; config `config/snapshots.php`,
  env `SNAPSHOT_RETENTION_DAYS`, default 30). Deletes snapshot rows older than
  the window but **always keeps each member's latest row per source**, so the
  current-state UI is never affected (churn/anniversary history lives in the
  separate, tiny `member_events` table). Bounds the snapshot tables to
  ~1.3 GB steady-state.
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
