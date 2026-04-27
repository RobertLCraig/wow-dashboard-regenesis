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

    /*
    |--------------------------------------------------------------------------
    | GitHub fetch settings
    |--------------------------------------------------------------------------
    |
    | When `simc:pull --fetch` runs, the fetcher walks the configured
    | repo+branch+directory via GitHub's Contents API, then downloads
    | each .simc file via raw.githubusercontent.com (which doesn't count
    | against API rate limits). Token is optional but recommended:
    | unauthenticated API listing is rate-limited to 60 req/hour, with
    | a token it's 5000/hour. The download URLs themselves are unmetered.
    |
    | Defaults track current Midnight Season 1; bump the branch + dir
    | when a new tier lands.
    */
    'github_repo' => env('SIMC_GITHUB_REPO', 'simulationcraft/simc'),
    'github_branch' => env('SIMC_GITHUB_BRANCH', 'midnight'),
    'github_profiles_dir' => env('SIMC_GITHUB_PROFILES_DIR', 'profiles/MID1'),
    'github_token' => env('SIMC_GITHUB_TOKEN', ''),
    'http_timeout' => (int) env('SIMC_HTTP_TIMEOUT', 15),
    'fetch_concurrency' => (int) env('SIMC_FETCH_CONCURRENCY', 10),
];
