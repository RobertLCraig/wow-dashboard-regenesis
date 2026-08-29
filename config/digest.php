<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Discord webhook URL for the weekly digest
    |--------------------------------------------------------------------------
    |
    | Create a webhook in the target Discord channel:
    |   Channel settings -> Integrations -> Webhooks -> New Webhook -> Copy URL.
    | Empty value disables posting (the digest:weekly command short-circuits
    | with a stdout dump rather than failing - useful for staging).
    */
    'discord_webhook_url' => env('DIGEST_DISCORD_WEBHOOK_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | HTTP timeout (seconds) for webhook posts
    |--------------------------------------------------------------------------
    */
    'timeout' => (int) env('DIGEST_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Day + time the weekly digest runs
    |--------------------------------------------------------------------------
    |
    | Day is ISO weekday (1=Mon ... 7=Sun). Time is HH:MM in the configured
    | timezone (defaults to raidhelper.timezone). Runs once per week via
    | the scheduler.
    */
    'cadence' => [
        'day' => (int) env('DIGEST_CADENCE_DAY', 7),       // Sunday
        'time' => env('DIGEST_CADENCE_TIME', '09:00'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database size cap and warning threshold
    |--------------------------------------------------------------------------
    |
    | The hosting plan's per-database limit in MB, and the fraction of it at
    | which the digest raises a warning. Hostinger's shared plans cap a MySQL
    | database at 3072 MB and auto-revoke INSERT/UPDATE above it, which 500s
    | every route (docs/ops-runbook.md, incident 2026-07-08). The cap belongs
    | to the plan, not to us, so it moves whenever the plan does - hence env
    | vars rather than literals in the digest.
    */
    'db_cap_mb' => (int) env('DIGEST_DB_CAP_MB', 3072),
    'db_warn_at' => (float) env('DIGEST_DB_WARN_AT', 0.8),
];
