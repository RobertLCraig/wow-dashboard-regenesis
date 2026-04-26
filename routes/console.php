<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily attendance pull from Raid-Helper. Runs early UK morning so
// fresh stats are ready before the typical raid prep window. The
// command short-circuits when RAID_HELPER_API_KEY is unset.
Schedule::command('raidhelper:sync-attendance')
    ->dailyAt('06:30')
    ->timezone(config('raidhelper.timezone', 'Europe/London'))
    ->onOneServer();

// Hourly wowaudit pull. Cheap (one /period + one /historical_data call,
// then per-tracked-character /historical_data/{id} for best_gear). The
// per-character calls hit the cache after the first pull each hour.
// Short-circuits cleanly when WOWAUDIT_API_KEY is unset.
Schedule::command('wowaudit:pull')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping();

// Daily pull of every Raid-Helper event into the local cache. Webhooks
// keep us in sync in real-time; this is the safety net that catches any
// missed deliveries and any events created before the webhook was wired.
// Officers can also trigger an on-demand pull via the dashboard button
// (rate-limited to 1/hour). Short-circuits cleanly when
// RAID_HELPER_API_KEY is unset.
Schedule::command('raidhelper:sync-events')
    ->dailyAt('06:15')
    ->timezone(config('raidhelper.timezone', 'Europe/London'))
    ->onOneServer()
    ->withoutOverlapping();
