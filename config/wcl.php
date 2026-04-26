<?php

return [
    /*
    |--------------------------------------------------------------------------
    | OAuth client credentials
    |--------------------------------------------------------------------------
    |
    | Register a v2 API client at https://www.warcraftlogs.com/api/clients/
    | (one per guild is enough). The client uses the client-credentials
    | grant - no per-user consent flow required, since we only ever read
    | publicly visible guild data.
    |
    | Both blank disables the WCL pull cleanly: the artisan command and
    | sync button print a "not configured" notice instead of erroring.
    */
    'client_id' => env('WCL_CLIENT_ID', ''),
    'client_secret' => env('WCL_CLIENT_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Endpoint URLs
    |--------------------------------------------------------------------------
    */
    'token_url' => env('WCL_TOKEN_URL', 'https://www.warcraftlogs.com/oauth/token'),
    'graphql_url' => env('WCL_GRAPHQL_URL', 'https://www.warcraftlogs.com/api/v2/client'),

    /*
    |--------------------------------------------------------------------------
    | Guild identity
    |--------------------------------------------------------------------------
    |
    | Used as the `guildName` / `guildServerSlug` / `guildServerRegion`
    | params on the reports query. Server slug is lower-case with hyphens
    | (`twisting-nether`, not `Twisting Nether`).
    */
    'guild_name' => env('WCL_GUILD_NAME', 'Regenesis'),
    'guild_server_slug' => env('WCL_GUILD_SERVER_SLUG', 'silvermoon'),
    'guild_server_region' => env('WCL_GUILD_SERVER_REGION', 'EU'),

    /*
    |--------------------------------------------------------------------------
    | HTTP timeout (seconds) per request
    |--------------------------------------------------------------------------
    */
    'timeout' => (int) env('WCL_TIMEOUT', 15),

    /*
    |--------------------------------------------------------------------------
    | Reports per pull
    |--------------------------------------------------------------------------
    |
    | How many of the latest reports to ask for on each sync. WCL caps the
    | per-page limit; 25 is a safe default that covers a couple of weeks
    | of raid nights without overlapping pages.
    */
    'reports_per_pull' => (int) env('WCL_REPORTS_PER_PULL', 25),
];
