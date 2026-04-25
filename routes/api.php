<?php

use App\Http\Controllers\Ingest\GrmSnapshotController;
use App\Http\Middleware\IngestBearerToken;
use Illuminate\Support\Facades\Route;

// GRM addon SavedVariables ingest. Bearer-authenticated, gzipped JSON
// body, returns 202 + snapshot id on success or 200 + noop:true on a
// duplicate payload hash. See tools/grm-sync/grm-sync.ps1.
Route::post('/ingest/grm', [GrmSnapshotController::class, 'store'])
    ->middleware(IngestBearerToken::class)
    ->name('ingest.grm');
