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
- [ ] #2 WHERE the database exceeds 80 percent of the 3 GB cap, THE DIGEST SHALL carry a visible
      warning naming the largest tables by size.
- [ ] #3 THE THRESHOLD and the cap SHALL be config values, not literals, because the cap is a
      property of the hosting plan and will change before the code does.
<!-- AC:END -->

## Tasks
- [ ] Query `information_schema.TABLES` for per-table size in the digest command
- [ ] Add the threshold warning and the top-tables breakdown
- [ ] Run the digest once against production data to see the real numbers
