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
    |   gear                                            - item_level_equipped + items
    |   raid_progression                                - per-instance H/M kills
    |   mythic_plus_scores_by_season:current            - season RIO score
    |   mythic_plus_weekly_highest_level_runs           - this week's best key per dungeon
    |   mythic_plus_previous_weekly_highest_level_runs  - last week's best (closes the gap when recent_runs rolls over)
    |   mythic_plus_recent_runs                         - up to 10 most recent runs (timed and untimed). Drives the per-day activity tracker
    |   mythic_plus_best_runs                           - season best per dungeon (one row each, the score-counting set)
    |   mythic_plus_alternate_runs                      - season second-best per dungeon (tyrannical/fortified pair-mate of best_runs)
    |
    | The four "runs" fields above all share the same row shape - dungeon,
    | mythic_level, completed_at, num_keystone_upgrades, score - so the
    | importer can dedupe across them by completed_at when persisting
    | individual run rows.
    */
    'profile_fields' => [
        'gear',
        'raid_progression',
        'mythic_plus_scores_by_season:current',
        'mythic_plus_weekly_highest_level_runs',
        'mythic_plus_previous_weekly_highest_level_runs',
        'mythic_plus_recent_runs',
        'mythic_plus_best_runs',
        'mythic_plus_alternate_runs',
    ],

    /*
    |--------------------------------------------------------------------------
    | Inter-batch delay (milliseconds)
    |--------------------------------------------------------------------------
    |
    | Sleep applied between batches dispatched via Http::pool. With
    | sync_concurrency=1 every batch is a single request, so this becomes
    | the per-request gap. At 1500ms that is ~40 reqs/min, comfortably
    | under Raider.IO's per-IP cap (which we observed enforced at well
    | under 600/min from a shared-hosting outbound IP - production was
    | seeing blanket 429s at concurrency=10 + 100ms). Set to 0 in tests.
    */
    'request_delay_ms' => (int) env('RAIDERIO_REQUEST_DELAY_MS', 1500),

    /*
    |--------------------------------------------------------------------------
    | Concurrency for officer-triggered sync
    |--------------------------------------------------------------------------
    |
    | Number of Raider.IO requests the importer fires in parallel via
    | Http::pool. Defaults to 1 (sequential) because shared-hosting
    | outbound IPs share a rate budget with whoever else lives on the
    | box; bursts of 10 parallel requests reliably tripped 429s on every
    | member. Sequential + 1500ms request_delay_ms is slow (~18 min for
    | a 700-member roster) but completes; the artisan command runs from
    | cron without a wall-clock limit.
    */
    'sync_concurrency' => (int) env('RAIDERIO_SYNC_CONCURRENCY', 1),

    /*
    |--------------------------------------------------------------------------
    | HTTP timeout (seconds) per request
    |--------------------------------------------------------------------------
    */
    'timeout' => (int) env('RAIDERIO_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Stale-ilvl recency window (days)
    |--------------------------------------------------------------------------
    |
    | Raider.IO returns the last gear blob it observed for a character,
    | which is only as fresh as the character's last login - and even
    | then RIO sometimes holds onto an older gear sample for months. A
    | parked alt or a poorly-refreshed profile ends up showing TWW-era
    | 700+ ilvls on a Midnight roster.
    |
    | We drop the ilvl when either signal says the data is stale:
    |   - GRM's last_online_at is older than the window, OR
    |   - The source's own freshness stamp is older than the window
    |     (RIO's gear.created_at, Blizzard's last_login_timestamp).
    |
    | Used by both the Raider.IO and Blizzard importers - the duration
    | is the same regardless of source, by design. Both are relative so
    | this self-adjusts across squishes and patches without anyone
    | touching config. Set to 0 to disable.
    */
    'stale_ilvl_window_days' => (int) env('RAIDERIO_STALE_ILVL_WINDOW_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | M+ run retention window (days)
    |--------------------------------------------------------------------------
    |
    | How long to keep individual run rows in member_mplus_runs before the
    | weekly prune sweeps them. The character page heatmap covers 13 weeks
    | (91 days) and the summary tiles cap at 90 days, so anything below 90
    | starts breaking the UI. 180 leaves a half-season of headroom for
    | trend lines without the table growing unbounded.
    |
    | Set to 0 to disable pruning entirely (keep every run forever).
    | Storage is tiny - ~5MB per guild at 180 days, a couple of dozen MB
    | at 12 months - so the practical decision is about query speed and
    | UI honesty, not disk.
    */
    'runs_retention_days' => (int) env('MPLUS_RUN_RETENTION_DAYS', 180),

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

        // Realms where GRM didn't strip the apostrophe / parens, observed
        // in production logs as repeated 400s from RIO. The collapsed
        // form on the left is what shows up in members.name after the
        // dash; the slug on the right is the form RIO actually accepts.
        'Blade\'sEdge'        => 'blades-edge',
        'Drek\'Thar'          => 'drekthar',
        'Aggra(Português)'    => 'aggra-portugues',
        'Pozzodell\'Eternità' => 'pozzo-delleternita',
    ],
];
