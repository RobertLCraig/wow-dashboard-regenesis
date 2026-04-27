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

// Twice-daily Raider.IO pull covering every active member in the local
// roster (no per-team gating, unlike wowaudit). One HTTP call per
// member, paced to stay well under RIO's unwritten ~600/min cap.
// Two pulls a day matches RIO's own profile refresh cadence and gives
// the heroic team's fluid roster a snapshot before each raid window.
Schedule::command('raiderio:pull')
    ->twiceDaily(7, 18)
    ->timezone(config('raidhelper.timezone', 'Europe/London'))
    ->onOneServer()
    ->withoutOverlapping();

// Twice-daily Blizzard profile pull. Authoritative ilvl source: data
// updates within minutes of a character logging out, so this is more
// reliable than RIO for the roster's ilvl column. Same cadence as
// raiderio:pull because both fundamentally depend on members logging
// out in-game; nothing finer is informative. Short-circuits cleanly
// when BLIZZARD_CLIENT_ID / BLIZZARD_CLIENT_SECRET are unset.
Schedule::command('blizzard:pull')
    ->twiceDaily(7, 18)
    ->timezone(config('raidhelper.timezone', 'Europe/London'))
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

// Weekly officer digest posted to Discord. Day + time configurable in
// config/digest.php (defaults to Sun 09:00 UK). Short-circuits cleanly
// when DIGEST_DISCORD_WEBHOOK_URL is empty so a pre-configured deploy
// doesn't error before the webhook is set up.
Schedule::command('digest:weekly')
    ->weeklyOn(
        (int) config('digest.cadence.day', 7),
        (string) config('digest.cadence.time', '09:00'),
    )
    ->timezone(config('raidhelper.timezone', 'Europe/London'))
    ->onOneServer();

// Daily WCL reports pull. One GraphQL call returns the latest N
// reports; idempotent on `code`. Short-circuits when WCL_CLIENT_ID /
// WCL_CLIENT_SECRET are unset so a pre-credential deploy is fine.
Schedule::command('wcl:pull')
    ->dailyAt('07:30')
    ->timezone(config('raidhelper.timezone', 'Europe/London'))
    ->onOneServer()
    ->withoutOverlapping();

// Pre-raid reminder pings to the event_reminder webhooks. Idempotent
// via event_reminder_log so a 5-minute tick that catches the same
// (event, offset) twice doesn't double-post. No-op when no event
// matches an offset window.
Schedule::command('events:dispatch-reminders')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping();

// Weekly SimulationCraft BiS profile refresh. Profiles change with
// patches and class tuning rounds, not minutes - one pull per week is
// plenty. --fetch downloads fresh .simc files from GitHub before
// parsing so production stays current without anyone touching the box.
// Short-circuits cleanly when SIMC_PROFILES_PATH is empty.
Schedule::command('simc:pull --fetch')
    ->weeklyOn(2, '04:00')  // Tuesday 04:00 UK, comfortably after weekly reset
    ->timezone(config('raidhelper.timezone', 'Europe/London'))
    ->onOneServer()
    ->withoutOverlapping();
