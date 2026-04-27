<?php

namespace App\Services\Simc;

use App\Models\BisProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Walks a configured local directory of SimulationCraft profile files,
 * runs each through SimcProfileParser, and upserts the results onto
 * bis_profiles. The loader is intentionally narrow: file in, table row
 * out. A future phase will plug in a GitHub fetcher that lands the
 * files in this same directory before the loader runs.
 */
class SimcProfileLoader
{
    public function __construct(
        private readonly SimcProfileParser $parser,
    ) {}

    /**
     * @return array{
     *   directory: string,
     *   files_seen: int,
     *   imported: int,
     *   skipped: int,
     *   errors: list<array{file:string, message:string}>,
     * }
     */
    public function loadFromDirectory(string $directory): array
    {
        if (! is_dir($directory)) {
            throw new \RuntimeException("SimC profiles directory does not exist: {$directory}");
        }

        $files = glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '*.simc') ?: [];
        sort($files);

        $imported = 0;
        $skipped = 0;
        $errors = [];
        $now = CarbonImmutable::now();

        foreach ($files as $absPath) {
            $contents = @file_get_contents($absPath);
            if ($contents === false) {
                $errors[] = ['file' => basename($absPath), 'message' => 'unreadable'];
                continue;
            }

            try {
                $parsed = $this->parser->parse($contents);
            } catch (\Throwable $e) {
                $errors[] = ['file' => basename($absPath), 'message' => $e->getMessage()];
                Log::warning('simc parse failed', ['file' => basename($absPath), 'error' => $e->getMessage()]);
                continue;
            }

            if ($parsed['class'] === null || $parsed['spec'] === null) {
                $skipped++;
                continue;
            }

            BisProfile::query()->updateOrCreate(
                [
                    'class' => $parsed['class'],
                    'spec' => $parsed['spec'],
                    'hero_talent' => $parsed['hero_talent'],
                ],
                [
                    'profile_name' => $parsed['profile_name'] ?? basename($absPath, '.simc'),
                    'source_path' => $absPath,
                    'parsed_data' => $parsed,
                    'captured_at' => $now,
                ]
            );
            $imported++;
        }

        return [
            'directory' => $directory,
            'files_seen' => count($files),
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }
}
