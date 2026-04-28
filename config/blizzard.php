<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Region
    |--------------------------------------------------------------------------
    |
    | Two-letter region code used to derive the API host and the
    | Battlenet-Namespace header. Valid values: us, eu, kr, tw, cn.
    | EU guild, so eu is the default.
    */
    'region' => env('BLIZZARD_REGION', 'eu'),

    /*
    |--------------------------------------------------------------------------
    | OAuth client credentials
    |--------------------------------------------------------------------------
    |
    | Register a client at https://develop.battle.net/access/clients,
    | grant_type=client_credentials. One client per environment is fine.
    | Both blank disables every blizzard:* command cleanly (sync button
    | + artisan print "not configured" instead of erroring).
    */
    'client_id' => env('BLIZZARD_CLIENT_ID', ''),
    'client_secret' => env('BLIZZARD_CLIENT_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | OAuth token endpoint
    |--------------------------------------------------------------------------
    |
    | Region-agnostic since 2022. Region-specific hosts (eu.battle.net,
    | us.battle.net etc.) still work if you prefer to pin one.
    */
    'oauth_token_url' => env('BLIZZARD_OAUTH_TOKEN_URL', 'https://oauth.battle.net/token'),

    /*
    |--------------------------------------------------------------------------
    | API base URL (region-derived by default)
    |--------------------------------------------------------------------------
    |
    | Override only if you need to pin a specific host. Empty means
    | "derive from region" -> https://{region}.api.blizzard.com.
    */
    'api_base_url' => env('BLIZZARD_API_BASE_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | Profile namespace (region-derived by default)
    |--------------------------------------------------------------------------
    |
    | Battle.net partitions data by namespace. For character profiles
    | this is `profile-{region}`, e.g. `profile-eu`. Empty means derive
    | from region. Override only if Blizzard add a new namespace flavour.
    */
    'namespace' => env('BLIZZARD_NAMESPACE', ''),

    /*
    |--------------------------------------------------------------------------
    | Dynamic namespace (region-derived by default)
    |--------------------------------------------------------------------------
    |
    | Used for guild data and frequently-changing game data
    | (/data/wow/guild/..., /data/wow/mythic-keystone/...). Format is
    | `dynamic-{region}`, e.g. `dynamic-eu`. Empty means derive from
    | region.
    */
    'dynamic_namespace' => env('BLIZZARD_DYNAMIC_NAMESPACE', ''),

    /*
    |--------------------------------------------------------------------------
    | Static namespace (region-derived by default)
    |--------------------------------------------------------------------------
    |
    | Used for rarely-changing reference data (item details, item
    | enchantments, journal/encounter info). Format is `static-{region}`,
    | e.g. `static-eu`. Empty means derive from region.
    */
    'static_namespace' => env('BLIZZARD_STATIC_NAMESPACE', ''),

    /*
    |--------------------------------------------------------------------------
    | Guild identity (for the guild roster endpoint)
    |--------------------------------------------------------------------------
    |
    | The /data/wow/guild/{realmSlug}/{nameSlug}/roster call needs both
    | parts in slug form: lowercase, hyphenated (e.g. realm
    | `twisting-nether`, guild name `regenesis`). Empty values disable
    | the guild roster pull cleanly.
    */
    'guild_realm_slug' => env('BLIZZARD_GUILD_REALM_SLUG', 'silvermoon'),
    'guild_name_slug' => env('BLIZZARD_GUILD_NAME_SLUG', 'regenesis'),

    /*
    |--------------------------------------------------------------------------
    | Locale
    |--------------------------------------------------------------------------
    |
    | Controls the language of localised strings (item names, talent
    | names, etc.). Doesn't affect ilvl. en_GB is the EU default.
    */
    'locale' => env('BLIZZARD_LOCALE', 'en_GB'),

    /*
    |--------------------------------------------------------------------------
    | HTTP timeout (seconds) per request
    |--------------------------------------------------------------------------
    */
    'timeout' => (int) env('BLIZZARD_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | OAuth token cache TTL (seconds)
    |--------------------------------------------------------------------------
    |
    | Tokens expire 24h after issue. We cache for slightly less so the
    | next call after expiry refreshes proactively rather than racing
    | with a 401. 23h is a comfortable buffer.
    */
    'token_cache_ttl' => (int) env('BLIZZARD_TOKEN_CACHE_TTL', 23 * 60 * 60),
];
