<?php

namespace App\Services\Simc;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Downloads SimulationCraft .simc profile files from GitHub onto local
 * disk so SimcProfileLoader can parse them. Two-stage fetch:
 *
 *   1. GitHub Contents API call to list the configured directory.
 *      Authenticated requests get 5000/hour, unauthenticated 60/hour.
 *      One call per fetch run, comfortably inside either ceiling.
 *
 *   2. Concurrent raw.githubusercontent.com downloads for each .simc
 *      file in the listing. Raw downloads aren't rate-limited and
 *      Http::pool keeps wall-clock under Hostinger's 30s cap on a
 *      ~50-file tier folder.
 *
 * Files land directly in the target directory (flat layout); existing
 * .simc files in that directory are left untouched unless GitHub
 * returned a same-name file, in which case they're overwritten.
 */
class SimcGithubFetcher
{
    public function __construct(
        private readonly string $repo,
        private readonly string $branch,
        private readonly string $profilesDir,
        private readonly string $token = '',
        private readonly int $timeoutSeconds = 15,
        private readonly int $concurrency = 10,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            repo: (string) config('simc.github_repo', 'simulationcraft/simc'),
            branch: (string) config('simc.github_branch', 'midnight'),
            profilesDir: trim((string) config('simc.github_profiles_dir', 'profiles/MID1'), '/'),
            token: (string) config('simc.github_token', ''),
            timeoutSeconds: (int) config('simc.http_timeout', 15),
            concurrency: (int) config('simc.fetch_concurrency', 10),
        );
    }

    /**
     * @return array{
     *   listed: int,
     *   downloaded: int,
     *   errored: int,
     *   target: string,
     *   errors: list<array{file:string, message:string}>,
     * }
     */
    public function fetchInto(string $targetDir): array
    {
        if (! is_dir($targetDir) && ! @mkdir($targetDir, 0755, true) && ! is_dir($targetDir)) {
            throw new \RuntimeException("Could not create target directory: {$targetDir}");
        }

        $entries = $this->listProfilesDirectory();
        $simcEntries = array_values(array_filter(
            $entries,
            fn (array $e) => ($e['type'] ?? null) === 'file' && str_ends_with((string) ($e['name'] ?? ''), '.simc'),
        ));

        if ($simcEntries === []) {
            return [
                'listed' => 0,
                'downloaded' => 0,
                'errored' => 0,
                'target' => $targetDir,
                'errors' => [],
            ];
        }

        $batches = array_chunk($simcEntries, max(1, $this->concurrency));
        $downloaded = 0;
        $errored = 0;
        $errors = [];

        foreach ($batches as $batch) {
            $responses = Http::pool(function (Pool $pool) use ($batch) {
                $reqs = [];
                foreach ($batch as $entry) {
                    $reqs[] = $pool
                        ->as((string) $entry['name'])
                        ->timeout($this->timeoutSeconds)
                        ->get($this->rawUrl((string) $entry['name']));
                }
                return $reqs;
            });

            foreach ($batch as $entry) {
                $name = (string) $entry['name'];
                $resp = $responses[$name] ?? null;

                if ($resp instanceof \Throwable) {
                    $errored++;
                    $errors[] = ['file' => $name, 'message' => $resp->getMessage()];
                    Log::warning('simc raw download failed', ['file' => $name, 'message' => $resp->getMessage()]);
                    continue;
                }
                if ($resp === null || ! $resp->successful()) {
                    $errored++;
                    $status = $resp?->status() ?? 0;
                    $errors[] = ['file' => $name, 'message' => "HTTP {$status}"];
                    Log::warning('simc raw download non-2xx', ['file' => $name, 'status' => $status]);
                    continue;
                }

                $body = (string) $resp->body();
                $bytes = @file_put_contents($targetDir . DIRECTORY_SEPARATOR . $name, $body);
                if ($bytes === false) {
                    $errored++;
                    $errors[] = ['file' => $name, 'message' => 'write failed'];
                    continue;
                }
                $downloaded++;
            }
        }

        return [
            'listed' => count($simcEntries),
            'downloaded' => $downloaded,
            'errored' => $errored,
            'target' => $targetDir,
            'errors' => $errors,
        ];
    }

    /**
     * Hit the Contents API for the configured profiles directory. The
     * response is a JSON array; each entry has at least `name`, `type`,
     * `path`. We don't paginate - tier folders top out around ~50
     * files, well under the API's 1000-per-page default.
     *
     * @return list<array<string,mixed>>
     */
    private function listProfilesDirectory(): array
    {
        $url = sprintf(
            'https://api.github.com/repos/%s/contents/%s',
            $this->repo,
            $this->profilesDir,
        );

        $request = Http::acceptJson()
            ->timeout($this->timeoutSeconds)
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28']);

        if ($this->token !== '') {
            $request = $request->withToken($this->token);
        }

        $response = $request->get($url, ['ref' => $this->branch]);

        if (! $response->successful()) {
            throw new \RuntimeException(sprintf(
                'GitHub Contents API failed: %d %s',
                $response->status(),
                mb_substr((string) $response->body(), 0, 200),
            ));
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new \RuntimeException('GitHub Contents API returned a non-array body');
        }
        return $body;
    }

    private function rawUrl(string $filename): string
    {
        return sprintf(
            'https://raw.githubusercontent.com/%s/%s/%s/%s',
            $this->repo,
            $this->branch,
            $this->profilesDir,
            rawurlencode($filename),
        );
    }
}
