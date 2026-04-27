<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Profiles directory
    |--------------------------------------------------------------------------
    |
    | Local filesystem path where the SimulationCraft profile (.simc)
    | files for the current tier live. The simc:pull command walks this
    | directory non-recursively and parses every .simc file it finds.
    |
    | Recommended: clone the repo somewhere writable on disk, then point
    | this at the current-tier subfolder, e.g.
    |   git clone https://github.com/simulationcraft/simc.git
    |   SIMC_PROFILES_PATH=/path/to/simc/profiles/MID1
    |
    | Empty disables the simc:pull command cleanly so a deploy without
    | the path set doesn't error.
    */
    'profiles_path' => env('SIMC_PROFILES_PATH', ''),

    /*
    |--------------------------------------------------------------------------
    | Tier label
    |--------------------------------------------------------------------------
    |
    | Cosmetic only - shown in the simc:pull output and stored on each
    | bis_profile row's source_path so officers can see at a glance
    | which tier the imported profiles came from.
    */
    'tier_label' => env('SIMC_TIER_LABEL', 'MID1'),
];
