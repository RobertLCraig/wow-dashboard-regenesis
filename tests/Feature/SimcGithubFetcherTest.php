<?php

use App\Services\Simc\SimcGithubFetcher;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'simc.github_repo' => 'simulationcraft/simc',
        'simc.github_branch' => 'midnight',
        'simc.github_profiles_dir' => 'profiles/MID1',
        'simc.github_token' => '',
        'simc.http_timeout' => 5,
        'simc.fetch_concurrency' => 5,
    ]);
});

function tempProfilesDir(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'simc-fetch-test-' . bin2hex(random_bytes(4));
    @mkdir($dir, 0755, true);
    return $dir;
}

function rmrfSimcTempDir(string $dir): void
{
    if (! is_dir($dir)) return;
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        is_dir($path) ? rmrfSimcTempDir($path) : @unlink($path);
    }
    @rmdir($dir);
}

it('lists profiles via the Contents API and downloads each .simc to the target', function () {
    $target = tempProfilesDir();

    Http::fake([
        'api.github.com/repos/simulationcraft/simc/contents/profiles/MID1*' => Http::response([
            ['name' => 'MID1_Death_Knight_Frost.simc', 'type' => 'file', 'path' => 'profiles/MID1/MID1_Death_Knight_Frost.simc'],
            ['name' => 'MID1_Druid_Balance.simc',     'type' => 'file', 'path' => 'profiles/MID1/MID1_Druid_Balance.simc'],
            ['name' => 'README.md',                   'type' => 'file', 'path' => 'profiles/MID1/README.md'],
            ['name' => 'subfolder',                   'type' => 'dir',  'path' => 'profiles/MID1/subfolder'],
        ], 200),
        'raw.githubusercontent.com/simulationcraft/simc/midnight/profiles/MID1/MID1_Death_Knight_Frost.simc' => Http::response("deathknight=\"MID1_Death_Knight_Frost\"\nspec=frost\n", 200),
        'raw.githubusercontent.com/simulationcraft/simc/midnight/profiles/MID1/MID1_Druid_Balance.simc'     => Http::response("druid=\"MID1_Druid_Balance\"\nspec=balance\n", 200),
    ]);

    $result = SimcGithubFetcher::fromConfig()->fetchInto($target);

    expect($result['listed'])->toBe(2);
    expect($result['downloaded'])->toBe(2);
    expect($result['errored'])->toBe(0);
    expect(file_exists($target . DIRECTORY_SEPARATOR . 'MID1_Death_Knight_Frost.simc'))->toBeTrue();
    expect(file_exists($target . DIRECTORY_SEPARATOR . 'MID1_Druid_Balance.simc'))->toBeTrue();
    expect(file_exists($target . DIRECTORY_SEPARATOR . 'README.md'))->toBeFalse();

    rmrfSimcTempDir($target);
});

it('attaches the bearer token when one is configured', function () {
    config(['simc.github_token' => 'gh-test-token']);
    $target = tempProfilesDir();

    Http::fake([
        'api.github.com/*' => Http::response([], 200),
    ]);

    SimcGithubFetcher::fromConfig()->fetchInto($target);

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), 'api.github.com/repos/simulationcraft/simc/contents/profiles/MID1')
        && $req->hasHeader('Authorization', 'Bearer gh-test-token')
    );

    rmrfSimcTempDir($target);
});

it('does not attach an auth header when no token is set', function () {
    $target = tempProfilesDir();

    Http::fake([
        'api.github.com/*' => Http::response([], 200),
    ]);

    SimcGithubFetcher::fromConfig()->fetchInto($target);

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), 'api.github.com')
        && ! $req->hasHeader('Authorization')
    );

    rmrfSimcTempDir($target);
});

it('throws when the Contents API returns non-2xx', function () {
    $target = tempProfilesDir();

    Http::fake([
        'api.github.com/*' => Http::response(['message' => 'Not Found'], 404),
    ]);

    expect(fn () => SimcGithubFetcher::fromConfig()->fetchInto($target))
        ->toThrow(\RuntimeException::class, 'GitHub Contents API failed: 404');

    rmrfSimcTempDir($target);
});

it('records errored downloads without aborting the run', function () {
    $target = tempProfilesDir();

    Http::fake([
        'api.github.com/*' => Http::response([
            ['name' => 'A.simc', 'type' => 'file'],
            ['name' => 'B.simc', 'type' => 'file'],
        ], 200),
        'raw.githubusercontent.com/*A.simc' => Http::response('warrior="A"', 200),
        'raw.githubusercontent.com/*B.simc' => Http::response('not found', 404),
    ]);

    $result = SimcGithubFetcher::fromConfig()->fetchInto($target);

    expect($result['listed'])->toBe(2);
    expect($result['downloaded'])->toBe(1);
    expect($result['errored'])->toBe(1);
    expect($result['errors'][0]['file'])->toBe('B.simc');
    expect(file_exists($target . DIRECTORY_SEPARATOR . 'A.simc'))->toBeTrue();
    expect(file_exists($target . DIRECTORY_SEPARATOR . 'B.simc'))->toBeFalse();

    rmrfSimcTempDir($target);
});

it('creates the target directory if it does not exist', function () {
    $target = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'simc-fetch-fresh-' . bin2hex(random_bytes(4));
    expect(is_dir($target))->toBeFalse();

    Http::fake([
        'api.github.com/*' => Http::response([], 200),
    ]);

    SimcGithubFetcher::fromConfig()->fetchInto($target);

    expect(is_dir($target))->toBeTrue();
    rmrfSimcTempDir($target);
});
