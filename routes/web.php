<?php

use App\Http\Controllers\Auth\DiscordController;
use App\Http\Middleware\OfficerOnly;
use Illuminate\Support\Facades\Route;

Route::view('/', 'landing')->name('landing');

Route::view('/auth/discord/unauthorised', 'auth.unauthorised')->name('auth.discord.unauthorised');
Route::view('/auth/discord/failed', 'auth.failed')->name('auth.discord.failed');

Route::get('/auth/discord', [DiscordController::class, 'start'])->name('auth.discord.start');
Route::get('/auth/discord/callback', [DiscordController::class, 'callback'])->name('auth.discord.callback');
Route::post('/logout', [DiscordController::class, 'logout'])->middleware('auth')->name('logout');

// Officer-only application surface. Every dashboard route lives behind
// auth + OfficerOnly so a removed Discord role takes effect within the
// configured cache TTL without requiring a re-login.
Route::middleware(['auth', OfficerOnly::class])->group(function () {
    Route::get('/dashboard', [\App\Http\Controllers\Dashboard\DashboardController::class, 'index'])->name('dashboard');
    Route::post('/dashboard/members/{member}/actions', [\App\Http\Controllers\Dashboard\MemberActionController::class, 'store'])->name('dashboard.member.actions.store');

    Route::get('/events', [\App\Http\Controllers\Events\EventController::class, 'index'])->name('events.index');
    Route::get('/events/new', [\App\Http\Controllers\Events\EventController::class, 'create'])->name('events.create');
    Route::post('/events', [\App\Http\Controllers\Events\EventController::class, 'store'])->name('events.store');
    Route::get('/events/{event}', [\App\Http\Controllers\Events\EventController::class, 'show'])
        ->where('event', '[0-9]+')->name('events.show');
    Route::delete('/events/{event}', [\App\Http\Controllers\Events\EventController::class, 'destroy'])
        ->where('event', '[0-9]+')->name('events.destroy');
});

// .ics download for a single event. Signed via HMAC(ics_uid|ics_sequence)
// so editing an event invalidates old shared links. No auth required so
// the link can be DM'd around or embedded; signature is the only key.
// {event} constrained to numeric so /events/1.ics doesn't match the
// auth-protected /events/{event} route as event=`1.ics`.
Route::get('/events/{event}.ics', [\App\Http\Controllers\Calendar\IcsController::class, 'show'])
    ->where('event', '[0-9]+')->name('event.ics');

// Per-user webcal:// subscription feed. Token is a random column on the
// User row; rotate from the settings page if leaked. Returns rolling
// 90-day window (subset includes recent past so calendar clients can
// show 'what was today').
Route::get('/calendar/{token}.ics', [\App\Http\Controllers\Calendar\IcsController::class, 'subscription'])
    ->name('calendar.subscription');
