<?php

use App\Models\BisProfile;
use App\Services\Simc\SimcProfileLoader;
use App\Services\Simc\SimcProfileParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('imports every .simc file in the directory and upserts one row per profile', function () {
    $dir = __DIR__ . '/../fixtures/simc';
    $loader = new SimcProfileLoader(new SimcProfileParser());

    $result = $loader->loadFromDirectory($dir);

    expect($result['files_seen'])->toBe(2);
    expect($result['imported'])->toBe(2);
    expect($result['skipped'])->toBe(0);
    expect($result['errors'])->toBe([]);

    $rows = BisProfile::query()->orderBy('class')->orderBy('spec')->get();
    expect($rows)->toHaveCount(2);
    expect($rows[0]->class)->toBe('death_knight');
    expect($rows[0]->spec)->toBe('frost');
    expect($rows[0]->hero_talent)->toBe('rider');
    expect($rows[0]->parsed_data['gear']['head']['item_id'])->toBe(249970);

    expect($rows[1]->class)->toBe('druid');
    expect($rows[1]->spec)->toBe('balance');
    expect($rows[1]->hero_talent)->toBeNull();
});

it('updates an existing row in place on a re-pull (idempotent)', function () {
    $dir = __DIR__ . '/../fixtures/simc';
    $loader = new SimcProfileLoader(new SimcProfileParser());

    $loader->loadFromDirectory($dir);
    $first = BisProfile::query()->where('class', 'death_knight')->where('spec', 'frost')->first();

    $loader->loadFromDirectory($dir);
    $second = BisProfile::query()->where('class', 'death_knight')->where('spec', 'frost')->first();

    expect(BisProfile::query()->count())->toBe(2);
    expect($second->id)->toBe($first->id);  // same row, refreshed
    expect($second->captured_at->gte($first->captured_at))->toBeTrue();
});

it('throws when the directory does not exist', function () {
    $loader = new SimcProfileLoader(new SimcProfileParser());
    expect(fn () => $loader->loadFromDirectory('/no/such/path'))
        ->toThrow(\RuntimeException::class, 'SimC profiles directory does not exist');
});

it('simc:pull short-circuits cleanly when SIMC_PROFILES_PATH is empty', function () {
    config(['simc.profiles_path' => '']);

    $this->artisan('simc:pull')
        ->expectsOutputToContain('simc:pull skipped')
        ->assertExitCode(0);
});

it('simc:pull processes the configured directory end-to-end', function () {
    config(['simc.profiles_path' => __DIR__ . '/../fixtures/simc']);

    $this->artisan('simc:pull')
        ->expectsOutputToContain('2 imported')
        ->assertExitCode(0);

    expect(BisProfile::query()->count())->toBe(2);
});

it('simc:pull --path overrides the configured directory', function () {
    config(['simc.profiles_path' => '/some/other/dir/that/should/be/overridden']);

    $this->artisan('simc:pull', ['--path' => __DIR__ . '/../fixtures/simc'])
        ->expectsOutputToContain('2 imported')
        ->assertExitCode(0);
});

it('simc:pull --fetch downloads from GitHub then parses the result', function () {
    $target = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'simc-pull-fetch-' . bin2hex(random_bytes(4));
    @mkdir($target, 0755, true);

    config([
        'simc.profiles_path' => $target,
        'simc.github_repo' => 'simulationcraft/simc',
        'simc.github_branch' => 'midnight',
        'simc.github_profiles_dir' => 'profiles/MID1',
        'simc.github_token' => '',
        'simc.http_timeout' => 5,
        'simc.fetch_concurrency' => 5,
    ]);

    Http::fake([
        'api.github.com/repos/simulationcraft/simc/contents/profiles/MID1*' => Http::response([
            ['name' => 'MID1_Druid_Balance.simc', 'type' => 'file'],
        ], 200),
        'raw.githubusercontent.com/*MID1_Druid_Balance.simc' => Http::response(
            file_get_contents(__DIR__ . '/../fixtures/simc/MID1_Druid_Balance.simc'),
            200,
        ),
    ]);

    $this->artisan('simc:pull', ['--fetch' => true])
        ->expectsOutputToContain('Fetched 1 files')
        ->expectsOutputToContain('1 imported')
        ->assertExitCode(0);

    expect(BisProfile::query()->where('class', 'druid')->count())->toBe(1);

    // cleanup
    foreach (glob($target . '/*') as $f) @unlink($f);
    @rmdir($target);
});
