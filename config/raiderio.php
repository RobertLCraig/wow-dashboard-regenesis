<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Region
    |--------------------------------------------------------------------------
    |
    | Two-letter region code Raider.IO expects in profile URLs. We're an
    | EU guild so this defaults to 'eu'. Valid values: us, eu, kr, tw, cn.
    */
    'region' => env('RAIDERIO_REGION', 'eu'),

    /*
    |--------------------------------------------------------------------------
    | Base URL
    |--------------------------------------------------------------------------
    */
    'base_url' => env('RAIDERIO_BASE_URL', 'https://raider.io/api/v1'),

    /*
    |--------------------------------------------------------------------------
    | Profile fields to request
    |--------------------------------------------------------------------------
    |
    | Comma-joined into the `fields` query param. Each field adds payload
    | size, so we keep this lean. Documented at
    | https://raider.io/api#!/Character/get_characters_profile.
    |
    |   gear                                       - item_level_equipped + items
    |   raid_progression                           - per-instance H/M kills
    |   mythic_plus_scores_by_season:current       - season RIO score
    |   mythic_plus_weekly_highest_level_runs      - this week's best key
    */
    'profile_fields' => [
        'gear',
        'raid_progression',
        'mythic_plus_scores_by_season:current',
        'mythic_plus_weekly_highest_level_runs',
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-request delay (milliseconds)
    |--------------------------------------------------------------------------
    |
    | Raider.IO doesn't publish a hard rate limit but the unwritten cap is
    | ~600 requests/minute for unauthenticated traffic. Pacing one request
    | per 100ms keeps us comfortably under that and well-behaved. Set to 0
    | in tests to skip the sleep entirely.
    */
    'request_delay_ms' => (int) env('RAIDERIO_REQUEST_DELAY_MS', 100),

    /*
    |--------------------------------------------------------------------------
    | HTTP timeout (seconds) per request
    |--------------------------------------------------------------------------
    */
    'timeout' => (int) env('RAIDERIO_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Default realm
    |--------------------------------------------------------------------------
    |
    | Slug used when a member's name doesn't carry a realm suffix. Most of
    | the guild lives on Silvermoon-EU so that's the safe default.
    */
    'default_realm_slug' => env('RAIDERIO_DEFAULT_REALM', 'silvermoon'),

    /*
    |--------------------------------------------------------------------------
    | Realm slug map
    |--------------------------------------------------------------------------
    |
    | GRM stores the realm portion of a character key with spaces and
    | apostrophes stripped (e.g. "TwistingNether"), but Raider.IO expects
    | the URL slug form ("twisting-nether"). For single-word realms a
    | simple lowercase works; for multi-word realms we need this map.
    |
    | Key: the collapsed realm name as it appears after the dash in
    |      members.name.
    | Value: the Raider.IO slug.
    |
    | Pre-populated with the multi-word EU realms most likely to appear
    | in a Silvermoon-anchored guild's roster. Extend as needed - the
    | importer logs an info-level note when a realm falls through to the
    | lowercase fallback so officers can spot ones to add.
    */
    'realm_slugs' => [
        'TwistingNether'      => 'twisting-nether',
        'ArgentDawn'          => 'argent-dawn',
        'BurningLegion'       => 'burning-legion',
        'BurningSteppes'      => 'burning-steppes',
        'DefiasBrotherhood'   => 'defias-brotherhood',
        'EmeraldDream'        => 'emerald-dream',
        'GrimBatol'           => 'grim-batol',
        'KazzakEU'            => 'kazzak',
        'KhazModan'           => 'khaz-modan',
        'KhazGoroth'          => 'khaz-goroth',
        'MoonGlade'           => 'moon-glade',
        'PozzodellEternita'   => 'pozzo-delleternita',
        'ScarshieldLegion'    => 'scarshield-legion',
        'SilverHand'          => 'silver-hand',
        'SteamwheedleCartel'  => 'steamwheedle-cartel',
        'StormScale'          => 'stormscale',
        'TheMaelstrom'        => 'the-maelstrom',
        'TheVentureCo'        => 'the-venture-co',
        'TheSha\'tar'         => 'the-shatar',
        'TheSchatar'          => 'the-shatar',
        'YsondreFR'           => 'ysondre',
    ],
];
