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
