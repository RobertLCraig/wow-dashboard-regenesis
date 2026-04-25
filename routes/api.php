<?php

use App\Http\Controllers\Ingest\GrmSnapshotController;
use App\Http\Controllers\Webhook\RaidHelperController;
use App\Http\Middleware\IngestBearerToken;
use App\Http\Middleware\RaidHelperWebhookAuth;
use Illuminate\Support\Facades\Route;

// GRM addon SavedVariables ingest. Bearer-authenticated, gzipped JSON
// body, returns 202 + snapshot id on success or 200 + noop:true on a
// duplicate payload hash. See tools/grm-sync/grm-sync.ps1.
Route::post('/ingest/grm', [GrmSnapshotController::class, 'store'])
    ->middleware(IngestBearerToken::class)
    ->name('ingest.grm');

// Raid-Helper push webhook. Configure in Discord with:
//   /webhooks set https://regenesis.enhanceify.co.uk/api/webhook/raidhelper
// Same endpoint receives event.create, event.edit, and event.delete - we
// upsert on every payload (the API contract returns success on 200, so
// idempotent re-applies are fine).
Route::post('/webhook/raidhelper', [RaidHelperController::class, 'handle'])
    ->middleware(RaidHelperWebhookAuth::class)
    ->name('webhook.raidhelper');
