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
- [x] #1 WHEN the weekly digest runs, IT SHALL include the current total database size in MB.
- [x] #2 WHERE the database exceeds 80 percent of the 3 GB cap, THE DIGEST SHALL carry a visible
      warning naming the largest tables by size.
- [x] #3 THE THRESHOLD and the cap SHALL be config values, not literals, because the cap is a
      property of the hosting plan and will change before the code does.
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
