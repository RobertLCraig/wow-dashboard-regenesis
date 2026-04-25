<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Server-scope API key
    |--------------------------------------------------------------------------
    |
    | Get from /apikey in your Discord server (NOT /usersettings apikey -
    | that's the user-scope key with much narrower permissions). Refresh
    | with the same command if it leaks.
    */
    'api_key' => env('RAID_HELPER_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Webhook key
    |--------------------------------------------------------------------------
    |
    | Set Raid-Helper to push events to /api/webhook/raidhelper via
    | three Discord commands (one per event type, the bot doesn't
    | accept "all"):
    |   /webhooks set type:event.create url:<dashboard>/api/webhook/raidhelper
    |   /webhooks set type:event.update url:<dashboard>/api/webhook/raidhelper
    |   /webhooks set type:event.delete url:<dashboard>/api/webhook/raidhelper
    | /webhooks show reveals the shared key Raid-Helper sends in the
    | Authorization header. /webhooks refresh-key rotates it.
    */
    'webhook_key' => env('RAID_HELPER_WEBHOOK_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Discord server (guild) the events live in
    |--------------------------------------------------------------------------
    |
    | Used in API URLs. Defaults to the same DISCORD_GUILD_ID we sign in
    | against - one guild, one Raid-Helper instance.
    */
    'server_id' => env('RAID_HELPER_SERVER_ID', env('DISCORD_GUILD_ID')),

    /*
    |--------------------------------------------------------------------------
    | Default channel for new events
    |--------------------------------------------------------------------------
    |
    | Right-click your raid-events channel in Discord and Copy ID.
    | Officers can override this on the create form.
    */
    'default_channel_id' => env('RAID_HELPER_DEFAULT_CHANNEL_ID'),

    /*
    |--------------------------------------------------------------------------
    | Templates to offer in the dropdown
    |--------------------------------------------------------------------------
    |
    | Raid-Helper has a long list of built-in templates; we curate a
    | subset relevant to retail WoW raiding. Override per-environment if
    | you ever switch to Classic / SoD. Template IDs are documented at
    | https://raid-helper.xyz/documentation/reference - or run
    | /quickcreate in Discord and copy the template number.
    */
    'templates' => [
        ['id' => '1', 'label' => 'Mythic+ Group'],
        ['id' => '2', 'label' => 'Raid (10-man)'],
        ['id' => '3', 'label' => 'Raid (20-man)'],
        ['id' => '4', 'label' => 'Raid (30-man)'],
        ['id' => '5', 'label' => 'Meeting / Hangout'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Time zone events render in
    |--------------------------------------------------------------------------
    |
    | Used for the .ics DTSTART;TZID=... lines and for parsing officer
    | input on the create form. Match the guild's primary tz.
    */
    'timezone' => env('APP_TIMEZONE', 'Europe/London'),
];
