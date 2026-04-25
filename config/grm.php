<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Guild key
    |--------------------------------------------------------------------------
    |
    | The literal key the GRM addon writes into its SavedVariables tables
    | (e.g. GRM_GuildMemberHistory_Save["Regenesis-Silvermoon"]). All
    | ingest payloads are required to declare this guild_key so we can
    | reject misdirected uploads.
    */
    'guild_key' => env('GUILD_KEY', 'Regenesis-Silvermoon'),

    /*
    |--------------------------------------------------------------------------
    | Ingest bearer token
    |--------------------------------------------------------------------------
    |
    | Shared secret between the Laravel app and the PowerShell sync tool
    | running on the user's PC. Generate with:
    |   php -r "echo bin2hex(random_bytes(32));"
    | Stored on the PC at %LOCALAPPDATA%\regenesis-grm\config.json.
    */
    'ingest_token' => env('GRM_INGEST_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Snapshot raw retention
    |--------------------------------------------------------------------------
    |
    | Days to keep the gzipped raw payloads under storage/app/snapshots/
    | before the prune command deletes them. Older payloads are still
    | replayable from git/backups if needed; this just bounds disk usage.
    */
    'raw_retention_days' => 90,

    /*
    |--------------------------------------------------------------------------
    | Inactive threshold
    |--------------------------------------------------------------------------
    |
    | Days after which a member is considered inactive. Used by the
    | "Recently Inactive" widget and by the became_inactive_30d signal
    | the differ emits when a member crosses this boundary.
    */
    'inactive_days' => 30,
];
