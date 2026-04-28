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

    /*
    |--------------------------------------------------------------------------
    | Bot token + announcements channel
    |--------------------------------------------------------------------------
    |
    | Bot token (NOT a user OAuth token) for reading messages out of the
    | guild's announcements channel for the Social page. Create a bot at
    | https://discord.com/developers/applications, give it the
    | `read_messages` + `read_message_history` permissions, invite it to
    | the server, and copy the token here. Both blank disables the
    | announcements pull cleanly.
    */
    'bot_token' => env('DISCORD_BOT_TOKEN', ''),
    'announcements_channel_id' => env('DISCORD_ANNOUNCEMENTS_CHANNEL_ID', ''),

    /*
    | Recruits / introductions channel. Same bot token as the
    | announcements feed, different channel: the parser scans messages
    | here for known WoW character names and stores Discord-id ->
    | member-name aliases so we can resolve raid-signup display names
    | back to a real character. Empty disables the recruits pull
    | cleanly.
    */
    'recruits_channel_id' => env('DISCORD_RECRUITS_CHANNEL_ID', ''),

    /*
    | How many messages to fetch on each pull (Discord caps at 100/page).
    | Announcements are infrequent so 50 covers a few weeks comfortably.
    */
    'announcements_pull_limit' => (int) env('DISCORD_ANNOUNCEMENTS_PULL_LIMIT', 50),

    /*
    | Days of history to surface on the Social page.
    */
    'announcements_window_days' => (int) env('DISCORD_ANNOUNCEMENTS_WINDOW_DAYS', 30),

    /*
    | HTTP timeout for Discord API calls.
    */
    'http_timeout' => (int) env('DISCORD_HTTP_TIMEOUT', 10),
];
