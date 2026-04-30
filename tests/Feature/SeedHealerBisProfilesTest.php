<?php

use App\Models\BisProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function writeHealerJson(array $profiles): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'healer-bis-');
    file_put_contents($tmp, json_encode([
        '_meta' => ['tier' => 'TEST'],
        'profiles' => $profiles,
    ], JSON_THROW_ON_ERROR));
    return $tmp;
}

it('upserts a row per healer-spec entry from the JSON file', function () {
    $path = writeHealerJson([
        [
            'class' => 'shaman',
            'spec' => 'restoration',
            'hero_talent' => null,
            'profile_name' => 'MID1_Shaman_Restoration_stub',
            'consumables' => ['flask' => 'flask_of_the_magisters_2'],
            'gear' => [],
        ],
        [
            'class' => 'priest',
            'spec' => 'discipline',
            'hero_talent' => null,
            'profile_name' => 'MID1_Priest_Discipline_stub',
            'consumables' => ['flask' => 'flask_of_the_magisters_2'],
            'gear' => [],
        ],
    ]);

    test()->artisan('bis:seed-healers', ['--path' => $path])
        ->expectsOutputToContain('2 healer BiS profiles upserted')
        ->assertExitCode(0);

    expect(BisProfile::query()->count())->toBe(2);

    $shaman = BisProfile::query()->where('class', 'shaman')->where('spec', 'restoration')->first();
    expect($shaman)->not->toBeNull();
    expect($shaman->profile_name)->toBe('MID1_Shaman_Restoration_stub');
    expect($shaman->parsed_data['consumables']['flask'])->toBe('flask_of_the_magisters_2');
    expect($shaman->parsed_data['gear'])->toBe([]);

    @unlink($path);
});

it('is idempotent - re-running replaces the same row in place', function () {
    $first = writeHealerJson([
        [
            'class' => 'druid',
            'spec' => 'restoration',
            'hero_talent' => null,
            'profile_name' => 'first_run',
            'consumables' => ['flask' => 'old_flask'],
            'gear' => [],
        ],
    ]);
    test()->artisan('bis:seed-healers', ['--path' => $first])->assertExitCode(0);

    $second = writeHealerJson([
        [
            'class' => 'druid',
            'spec' => 'restoration',
            'hero_talent' => null,
            'profile_name' => 'second_run',
            'consumables' => ['flask' => 'new_flask'],
            'gear' => ['head' => ['slot' => 'head', 'name' => 'helm', 'item_id' => 1234, 'enchant_id' => 7777, 'gem_ids' => [], 'bonus_ids' => [], 'ilevel' => 289]],
        ],
    ]);
    test()->artisan('bis:seed-healers', ['--path' => $second])->assertExitCode(0);

    expect(BisProfile::query()->count())->toBe(1);
    $row = BisProfile::query()->first();
    expect($row->profile_name)->toBe('second_run');
    expect($row->parsed_data['consumables']['flask'])->toBe('new_flask');
    expect($row->parsed_data['gear']['head']['item_id'])->toBe(1234);

    @unlink($first);
    @unlink($second);
});

it('skips entries missing class or spec without aborting the run', function () {
    $path = writeHealerJson([
        ['class' => 'shaman', 'spec' => 'restoration', 'profile_name' => 'ok', 'consumables' => [], 'gear' => []],
        ['class' => 'druid', 'profile_name' => 'broken', 'consumables' => [], 'gear' => []], // missing spec
        'not even an object',
    ]);

    test()->artisan('bis:seed-healers', ['--path' => $path])
        ->expectsOutputToContain('1 healer BiS profiles upserted')
        ->assertExitCode(0);

    expect(BisProfile::query()->count())->toBe(1);
    @unlink($path);
});

it('errors cleanly when the JSON file does not exist', function () {
    test()->artisan('bis:seed-healers', ['--path' => '/no/such/file.json'])
        ->expectsOutputToContain('not found')
        ->assertExitCode(1);
});

it('errors cleanly when the JSON is malformed', function () {
    $path = tempnam(sys_get_temp_dir(), 'healer-bad-');
    file_put_contents($path, '{ this is not json');

    test()->artisan('bis:seed-healers', ['--path' => $path])
        ->expectsOutputToContain('Invalid JSON')
        ->assertExitCode(1);

    @unlink($path);
});

it('produces a profile that the BiS comparison resolver actually picks up', function () {
    // End-to-end smoke: a healer with Blizzard equipment data + a stub
    // profile from this seeder should yield a non-null comparison.
    $path = writeHealerJson([
        [
            'class' => 'shaman',
            'spec' => 'restoration',
            'hero_talent' => null,
            'profile_name' => 'MID1_Shaman_Restoration_stub',
            'consumables' => ['flask' => 'flask_of_the_magisters_2'],
            'gear' => [],
        ],
    ]);
    test()->artisan('bis:seed-healers', ['--path' => $path])->assertExitCode(0);

    $member = \App\Models\Member::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'name' => 'TestRestoSham-Silvermoon',
        'class' => 'SHAMAN',
        'level' => 90,
        'rank_index' => 5,
        'status' => \App\Models\Member::STATUS_ACTIVE,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'last_online_at' => now(),
    ]);
    config(['grm.guild_key' => 'Regenesis-Silvermoon']);

    // Blizzard profile-summary snapshot supplies the spec.
    $blizzSnap = \App\Models\Snapshot::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'captured_at' => now(),
        'source' => \App\Models\Snapshot::SOURCE_BLIZZARD,
        'payload_hash' => bin2hex(random_bytes(8)),
    ]);
    \App\Models\MemberSnapshot::query()->create([
        'snapshot_id' => $blizzSnap->id,
        'member_id' => $member->id,
        'raw_json' => ['active_spec' => ['name' => 'Restoration', 'id' => 264]],
    ]);

    // Blizzard equipment snapshot supplies the gear.
    $equipSnap = \App\Models\Snapshot::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'captured_at' => now(),
        'source' => \App\Models\Snapshot::SOURCE_BLIZZARD_EQUIPMENT,
        'payload_hash' => bin2hex(random_bytes(8)),
    ]);
    \App\Models\MemberEquipmentSnapshot::query()->create([
        'snapshot_id' => $equipSnap->id,
        'member_id' => $member->id,
        'pieces' => [
            ['slot' => ['type' => 'HEAD'], 'item' => ['id' => 12345], 'name' => 'Healer Helm', 'enchantments' => [], 'sockets' => []],
        ],
    ]);

    $cmp = (new \App\Services\Bis\BisComparisonService())->compareForMember($member);
    expect($cmp)->not->toBeNull();
    expect($cmp['spec'])->toBe('restoration');
    expect($cmp['profile_name'])->toBe('MID1_Shaman_Restoration_stub');
    // Player's actual head item shows up; BiS column is empty (gear = []).
    expect($cmp['slots']['head']['actual_item_id'])->toBe(12345);
    expect($cmp['slots']['head']['bis_item_id'])->toBeNull();
    // Empty BiS gear means no missing/wrong issues count toward the
    // roster's BiS-issues column.
    $issues = (new \App\Services\Bis\BisComparisonService())->countIssues($cmp);
    expect($issues['missing_enchants'])->toBe(0);
    expect($issues['wrong_enchants'])->toBe(0);

    @unlink($path);
});
