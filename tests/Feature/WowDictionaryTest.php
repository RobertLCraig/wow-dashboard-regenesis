<?php

use App\Support\WowDictionary;

function dictWithFiles(array $enchants, array $gems): WowDictionary
{
    $dir = sys_get_temp_dir() . '/wowdict-' . uniqid();
    @mkdir($dir, 0777, true);
    $enchantsPath = "{$dir}/enchants.json";
    $gemsPath = "{$dir}/gems.json";
    file_put_contents($enchantsPath, json_encode($enchants));
    file_put_contents($gemsPath, json_encode($gems));
    return new WowDictionary($enchantsPath, $gemsPath);
}

it('returns the entry for a known enchant id', function () {
    $dict = dictWithFiles(
        enchants: [
            '_meta' => ['updated_at' => '2026-04-01', 'patch' => 'TEST'],
            'entries' => [
                '7987' => ['name' => 'Mark of the Worldsoul', 'spell_id' => 458985],
            ],
        ],
        gems: ['entries' => []],
    );

    expect($dict->enchant(7987))->toBe(['name' => 'Mark of the Worldsoul', 'spell_id' => 458985]);
});

it('treats empty-string names as not-yet-filled and returns null', function () {
    $dict = dictWithFiles(
        enchants: ['entries' => ['7987' => ['name' => '', 'spell_id' => null]]],
        gems: ['entries' => []],
    );

    expect($dict->enchant(7987))->toBeNull();
});

it('returns null for an unknown id', function () {
    $dict = dictWithFiles(
        enchants: ['entries' => ['7987' => ['name' => 'Filled', 'spell_id' => null]]],
        gems: ['entries' => []],
    );

    expect($dict->enchant(9999))->toBeNull();
});

it('returns the entry for a known gem id', function () {
    $dict = dictWithFiles(
        enchants: ['entries' => []],
        gems: [
            '_meta' => ['updated_at' => '2026-04-01'],
            'entries' => ['240983' => ['name' => 'Indecipherable Eversong Diamond']],
        ],
    );

    expect($dict->gem(240983))->toBe(['name' => 'Indecipherable Eversong Diamond']);
});

it('handles a missing dictionary file gracefully', function () {
    $dict = new WowDictionary('/nope/enchants.json', '/nope/gems.json');

    expect($dict->enchant(7987))->toBeNull();
    expect($dict->gem(240983))->toBeNull();
    expect($dict->freshness())->toMatchArray([
        'updated_at' => null,
        'patch' => null,
        'missing_names' => 0,
        'total_ids' => 0,
    ]);
});

it('reports the older of the two _meta dates as the freshness anchor', function () {
    $dict = dictWithFiles(
        enchants: [
            '_meta' => ['updated_at' => '2026-01-01', 'patch' => 'OLD'],
            'entries' => ['7987' => ['name' => 'Filled', 'spell_id' => null]],
        ],
        gems: [
            '_meta' => ['updated_at' => '2026-04-01', 'patch' => 'NEW'],
            'entries' => ['240983' => ['name' => 'Filled too']],
        ],
    );

    $fresh = $dict->freshness();
    expect($fresh['updated_at']?->toDateString())->toBe('2026-01-01');
    expect($fresh['missing_names'])->toBe(0);
    expect($fresh['total_ids'])->toBe(2);
});

it('counts entries with empty names toward missing_names', function () {
    $dict = dictWithFiles(
        enchants: [
            '_meta' => ['updated_at' => '2026-04-01'],
            'entries' => [
                '7987' => ['name' => 'Filled', 'spell_id' => null],
                '8001' => ['name' => '', 'spell_id' => null],
                '8017' => ['name' => '   ', 'spell_id' => null],
            ],
        ],
        gems: [
            '_meta' => ['updated_at' => '2026-04-01'],
            'entries' => ['240983' => ['name' => 'Filled too']],
        ],
    );

    expect($dict->freshness()['missing_names'])->toBe(2);
    expect($dict->freshness()['total_ids'])->toBe(4);
});
