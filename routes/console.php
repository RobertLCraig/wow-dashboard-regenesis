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

// Daily Blizzard guild roster pull. Authoritative "who is in the guild
// right now" source - one HTTP call covers the whole roster, no
// fan-out. Hybrid model: this owns membership, GRM still owns notes /
// alts / join dates / officer flags. Daily is plenty (Blizzard's
// roster cache lags by minutes anyway). Short-circuits cleanly when
// credentials or guild slugs are unset.
Schedule::command('blizzard:pull-roster')
    ->dailyAt('06:45')
    ->timezone(config('raidhelper.timezone', 'Europe/London'))
    ->onOneServer()
    ->withoutOverlapping();

// Twice-daily per-piece equipment pull. The /equipment endpoint
// returns the full equipped_items array per character (item ids,
// enchants, sockets, bonus list) - the data behind pre-raid readiness
// checks. Same cadence as profile because both depend on logout, but
// run on a separate schedule slot so a slow equipment fan-out can't
// stall the lighter ilvl pull. Short-circuits cleanly when credentials
// are unset.
Schedule::command('blizzard:pull-equipment')
    ->twiceDaily(7, 18)
    ->timezone(config('raidhelper.timezone', 'Europe/London'))
    ->onOneServer()
    ->withoutOverlapping();

// Daily Blizzard mythic-keystone-profile pull. Stored alongside RIO
// for cross-validation and outage fallback; RIO stays the day-to-day
// display source. Daily is plenty - M+ rating settles per weekly
// reset and a single daily pull captures most movement. Short-circuits
// cleanly when credentials are unset.
Schedule::command('blizzard:pull-mplus')
    ->dailyAt('08:00')
    ->timezone(config('raidhelper.timezone', 'Europe/London'))
    ->onOneServer()
    ->withoutOverlapping();

// Daily Blizzard raid-encounters pull. Powers AOTC/CE detection and
// "did this trial actually clear Heroic X" checks without needing
// wowaudit opt-in coverage. Daily is more than enough - kills don't
// happen often enough to need finer granularity. Short-circuits
// cleanly when credentials are unset.
Schedule::command('blizzard:pull-raids')
    ->dailyAt('08:15')
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

// Hourly Discord announcements pull. The announcements channel is
// low-traffic (a few posts per week typically) so hourly is generous;
// catches transmog contests, drunken raid nights, server status notes
// before the Social page is loaded next. Short-circuits cleanly when
// DISCORD_BOT_TOKEN / DISCORD_ANNOUNCEMENTS_CHANNEL_ID are unset.
Schedule::command('discord:fetch-announcements')
    ->hourly()
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
