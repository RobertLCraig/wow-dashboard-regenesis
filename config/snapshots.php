<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Snapshot retention window (days)
    |--------------------------------------------------------------------------
    |
    | How long to keep historical rows in the per-member snapshot tables
    | before `snapshots:prune` sweeps them. These tables are append-only:
    | every source pull (Blizzard every 30 min, Raider.IO every 3h,
    | wowaudit hourly, GRM ingest) writes a fresh row per member, each
    | carrying a fat JSON payload. Left unbounded they grew to ~2.7 GB and
    | tipped the database over Hostinger's 3 GB per-database cap, which
    | auto-revokes INSERT/UPDATE and 500s every request (see the incident
    | note in docs/planning/next-session.md, 2026-07-08).
    |
    | Crucially, NO dashboard widget needs deep snapshot history: churn and
    | anniversaries read `member_events` (a separate, tiny table), and the
    | roster/character/BiS surfaces only read each member's *latest* row per
    | source. The prune therefore always protects each member's most-recent
    | row per source regardless of age, so the current-state UI never loses
    | data no matter how short this window is. The window only governs how
    | much *superseded* history we retain for ad-hoc inspection.
    |
    | 30 days is generous headroom on top of the "latest row is always kept"
    | guarantee while bounding the snapshot tables to ~1.3 GB steady-state.
    | Set to 0 to disable pruning entirely (keep every row forever).
    */
    'retention_days' => (int) env('SNAPSHOT_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Child snapshot tables to prune
    |--------------------------------------------------------------------------
    |
    | Every per-member snapshot table shares the same shape: a
    | (snapshot_id, member_id) pair, with captured_at + source living on the
    | parent `snapshots` row. The prune joins each of these back to
    | `snapshots` for the age + source and never touches the parent rows
    | (they also anchor `member_events`, which cascade-deletes and must be
    | preserved for the churn/anniversary history).
    */
    'tables' => [
        'member_snapshots',
        'member_equipment_snapshots',
        'member_raid_snapshots',
        'member_social_snapshots',
        'member_mplus_snapshots',
    ],

    /*
    |--------------------------------------------------------------------------
    | Delete chunk size
    |--------------------------------------------------------------------------
    |
    | Rows are deleted in batches so a single statement stays well under
    | Hostinger's 120s MAX_STATEMENT_TIME even on the first sweep of a
    | badly-overgrown table.
    */
    'prune_chunk' => (int) env('SNAPSHOT_PRUNE_CHUNK', 2000),
];
