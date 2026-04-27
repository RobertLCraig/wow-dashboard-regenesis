<?php

namespace App\Console\Commands;

use App\Services\Simc\SimcProfileLoader;
use App\Services\Simc\SimcProfileParser;
use Illuminate\Console\Command;

/**
 * Walks the configured SIMC_PROFILES_PATH directory and upserts the
 * parsed BiS data onto bis_profiles, one row per (class, spec,
 * hero_talent). Designed to run on a weekly schedule once the GitHub
 * fetcher (Phase 2) lands - profiles change with patches, not minutes.
 *
 *   php artisan simc:pull
 *   php artisan simc:pull --path=/some/other/profiles
 */
class PullSimcProfiles extends Command
{
    protected $signature = 'simc:pull {--path= : Override SIMC_PROFILES_PATH for this run}';

    protected $description = 'Parse SimulationCraft .simc profiles into bis_profiles for BiS comparison';

    public function handle(SimcProfileLoader $loader): int
    {
        $path = (string) ($this->option('path') ?? config('simc.profiles_path'));
        if ($path === '') {
            $this->info('simc:pull skipped (SIMC_PROFILES_PATH not set).');
            return self::SUCCESS;
        }

        try {
            $result = $loader->loadFromDirectory($path);
        } catch (\Throwable $e) {
            $this->error('simc:pull failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info(sprintf(
            '%d .simc files in %s: %d imported, %d skipped, %d errored.',
            $result['files_seen'],
            $result['directory'],
            $result['imported'],
            $result['skipped'],
            count($result['errors']),
        ));

        foreach ($result['errors'] as $err) {
            $this->warn(sprintf('  - %s: %s', $err['file'], $err['message']));
        }

        return self::SUCCESS;
    }
}
