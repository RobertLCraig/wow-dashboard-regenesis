<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Discord guild + role IDs
    |--------------------------------------------------------------------------
    |
    | Defaults match the Regenesis Discord server. Overridable via env so
    | a dev / staging environment can point at a different test server
    | without code changes.
    */
    'guild_id' => env('DISCORD_GUILD_ID', '1247256415542841416'),

    /*
    | Permission tiers, highest authority first. v1 treats all three the
    | same (membership in any grants full dashboard access). v2 will tie
    | individual Laravel Gates to specific tiers without touching call
    | sites - the data is here from day 1.
    */
    'roles' => [
        'gm' => env('DISCORD_ROLE_GM'),
        'big6' => env('DISCORD_ROLE_BIG6'),
        'officer' => env('DISCORD_ROLE_OFFICER'),
    ],

    /*
    | How long to trust a fetched role list before re-checking against
    | Discord's API. Cap-load on Discord rate limits while still
    | reflecting role changes within ~5 minutes.
    */
    'role_cache_ttl_minutes' => 5,
];
