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
});
