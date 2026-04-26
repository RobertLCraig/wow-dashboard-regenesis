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
    | Channel dropdown options
    |--------------------------------------------------------------------------
    |
    | Pre-populated channel choices shown in the event creator's channel
    | selector. Officers can still paste an arbitrary channel ID via the
    | "Other..." option, so this list doesn't need to be exhaustive -
    | just the ones used most often.
    */
    'channels' => [
        ['id' => '1430231966686511124', 'name' => 'social-events',       'label' => '📅 social-events'],
        ['id' => '1247281653777301714', 'name' => 'heroic-raid-signup',  'label' => '✍ heroic-raid-signup'],
        ['id' => '1423413329954603039', 'name' => 'mythic-raid-signup',  'label' => '✍ mythic-raid-signup'],
        ['id' => '1341149654045429962', 'name' => 'keynight-signup',     'label' => '✍ keynight-signup'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-team event presets
    |--------------------------------------------------------------------------
    |
    | Drives the quick-create panels on the team dashboard pages
    | (/dashboard/heroic, /dashboard/mythic, /dashboard/keynight). Each
    | team has a signup channel and a list of weekday integers (1=Mon ..
    | 7=Sun, ISO-8601 / Carbon::dayOfWeekIso) that the form pre-fills as
    | "Next Tue / Next Thu / ..." pills. Template id is the Raid-Helper
    | template the quick create posts with.
    */
    'teams' => [
        'heroic' => [
            'label' => 'Heroic Raid',
            'channel_id' => '1247281653777301714', // heroic-raid-signup
            'raid_days' => [2, 4],                 // Tue, Thu
            'template_id' => '9',                  // role + spec
        ],
        'mythic' => [
            'label' => 'Mythic Raid',
            'channel_id' => '1423413329954603039', // mythic-raid-signup
            'raid_days' => [3, 7],                 // Wed, Sun
            'template_id' => '9',
        ],
        'keynight' => [
            'label' => 'Keynight (M+)',
            'channel_id' => '1341149654045429962', // keynight-signup
            'raid_days' => [1],                    // Mon
            'template_id' => '9',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default reminder offsets
    |--------------------------------------------------------------------------
    |
    | Pre-populates the announcements section of the create form so
    | officers don't have to type these every time. Each entry is
    | [minutes_before, message]. Officers can add/remove rows on the
    | form before submitting.
    */
    'default_announcements' => [
        ['minutes' => 1,   'message' => 'Event starting now!'],
        ['minutes' => 30,  'message' => 'Event starting in 30 minutes!'],
        ['minutes' => 120, 'message' => 'Event starting in 2 hours!'],
        ['minutes' => 240, 'message' => 'Event starting in 4 hours!'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Templates to offer in the dropdown
    |--------------------------------------------------------------------------
    |
    | Raid-Helper templates control how members sign up (not event size).
    | We surface the two we actually use: 9 for raids (role + spec
    | picker) and 1 for social events (accept / maybe / decline). Other
    | templates exist (2-5: class picker; 6: role picker; 7: role +
    | support; 8: yes-only) - add them here if you ever need them. The
    | order here is the order in the form dropdown; first entry is the
    | one new officers see selected by default.
    */
    'templates' => [
        ['id' => '9', 'label' => 'Raid event (role + spec)'],
        ['id' => '1', 'label' => 'Social event (accept / maybe / decline)'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Time zone events render in
    |--------------------------------------------------------------------------
    |
    | Used for the .ics DTSTART;TZID=... lines, the create form's
    | datetime-local input default, and the events list display.
    | Defaults to Europe/Paris because WoW EU realms run on CET/CEST.
    | Decoupled from APP_TIMEZONE so changing it doesn't affect
    | Laravel's internal timestamps / logs.
    */
    'timezone' => env('RAIDHELPER_TIMEZONE', 'Europe/Paris'),

    /*
    |--------------------------------------------------------------------------
    | Default time of day for new events
    |--------------------------------------------------------------------------
    |
    | Pre-fills the create form's datetime-local input at this time on
    | tomorrow's date in the configured timezone. Officers can change
    | the date and time before submitting.
    */
    'default_time_of_day' => env('RAIDHELPER_DEFAULT_TIME', '19:30'),
];
