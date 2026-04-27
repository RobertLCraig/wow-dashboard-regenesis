<?php

use App\Models\BisProfile;
use App\Services\Simc\SimcProfileLoader;
use App\Services\Simc\SimcProfileParser;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
