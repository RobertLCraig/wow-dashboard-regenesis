<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Team API key
    |--------------------------------------------------------------------------
    |
    | From https://wowaudit.com -> Settings -> API. Authenticates every
    | request. Sent as Authorization: Bearer <key>. Empty key disables
    | the wowaudit:pull command without erroring (lets cron stay armed
    | on a pre-keyed deploy).
    */
    'api_key' => env('WOWAUDIT_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Base URL
    |--------------------------------------------------------------------------
    */
    'base_url' => env('WOWAUDIT_BASE_URL', 'https://wowaudit.com/v1'),

    /*
    |--------------------------------------------------------------------------
    | Item slots used to compute equipped ilvl
    |--------------------------------------------------------------------------
    |
    | Average of these slot ilvls = equipped ilvl, mirroring how Blizzard
    | computes the in-game number. Omitting tabard/shirt because Blizz
    | doesn't count them.
    */
    'gear_slots' => [
        'head', 'neck', 'shoulder', 'back', 'chest', 'wrist', 'hands',
        'waist', 'legs', 'feet', 'finger_1', 'finger_2', 'trinket_1',
        'trinket_2', 'main_hand', 'off_hand',
    ],
];
