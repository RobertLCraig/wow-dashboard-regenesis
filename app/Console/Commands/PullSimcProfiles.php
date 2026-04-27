<?php

namespace App\Console\Commands;

use App\Services\Simc\SimcGithubFetcher;
use App\Services\Simc\SimcProfileLoader;
use Illuminate\Console\Command;

/**
 * Walks the configured SIMC_PROFILES_PATH directory and upserts the
 * parsed BiS data onto bis_profiles, one row per (class, spec,
 * hero_talent).
 *
 *   php artisan simc:pull            # load whatever's already on disk
 *   php artisan simc:pull --fetch    # download from GitHub first
 *   php artisan simc:pull --path=... # override SIMC_PROFILES_PATH
 *
 * The scheduled job uses --fetch so production stays current without
 * anyone touching the box. Manual dev runs can omit --fetch and reuse
 * a local clone of simulationcraft/simc.
 */
class PullSimcProfiles extends Command
{
    protected $signature = 'simc:pull
        {--path= : Override SIMC_PROFILES_PATH for this run}
        {--fetch : Download fresh .simc files from GitHub before parsing}';

    protected $description = 'Parse SimulationCraft .simc profiles into bis_profiles for BiS comparison';

    public function handle(SimcProfileLoader $loader): int
    {
        $path = (string) ($this->option('path') ?? config('simc.profiles_path'));
        if ($path === '') {
            $this->info('simc:pull skipped (SIMC_PROFILES_PATH not set).');
            return self::SUCCESS;
        }

        if ($this->option('fetch')) {
            try {
                $fetch = SimcGithubFetcher::fromConfig()->fetchInto($path);
            } catch (\Throwable $e) {
                $this->error('simc fetch failed: ' . $e->getMessage());
                return self::FAILURE;
            }
            $this->info(sprintf(
                'Fetched %d files into %s (%d errored).',
                $fetch['downloaded'],
                $fetch['target'],
                $fetch['errored'],
            ));
            foreach ($fetch['errors'] as $err) {
                $this->warn(sprintf('  - %s: %s', $err['file'], $err['message']));
            }
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
