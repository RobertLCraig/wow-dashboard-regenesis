<?php

use App\Models\Member;
use App\Models\MemberSnapshot;
use App\Models\Snapshot;
use App\Services\Wowaudit\WowauditClient;
use App\Services\Wowaudit\WowauditSnapshotImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'wowaudit.api_key' => 'test-wowaudit-key',
        'wowaudit.base_url' => 'https://wowaudit.test/v1',
        'wowaudit.gear_slots' => [
            'head', 'neck', 'shoulder', 'back', 'chest', 'wrist', 'hands',
            'waist', 'legs', 'feet', 'finger_1', 'finger_2', 'trinket_1',
            'trinket_2', 'main_hand', 'off_hand',
        ],
        'grm.guild_key' => 'Regenesis-Silvermoon',
    ]);
});

function fakeMember(string $name, array $overrides = []): Member
{
    return Member::query()->create(array_replace([
        'guild_key' => 'Regenesis-Silvermoon',
        'name' => $name,
        'class' => 'PRIEST',
        'level' => 80,
        'rank_index' => 5,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ], $overrides));
}

function fakePeriodResponse(int $period = 1024): array
{
    return [
        'current_period' => $period,
        'current_season' => ['id' => 1, 'name' => 'Season 1'],
    ];
}

function fakeCharactersResponse(): array
{
    return [
        ['id' => 1, 'name' => 'Sheday', 'realm' => 'Stormrage', 'class' => 'Paladin', 'role' => 'Melee', 'rank' => 'Main', 'status' => 'tracking'],
        ['id' => 2, 'name' => 'Tute', 'realm' => 'Stormrage', 'class' => 'Priest', 'role' => 'Heal', 'rank' => 'Main', 'status' => 'tracking'],
        ['id' => 3, 'name' => 'Notinguild', 'realm' => 'Stormrage', 'class' => 'Mage', 'role' => 'Ranged', 'rank' => 'Alt', 'status' => 'tracking'],
    ];
}

function fakeHistoricalResponse(int $period = 1024): array
{
    return [
        'period' => $period,
        'characters' => [
            [
                'id' => 1,
                'name' => 'Sheday',
                'realm' => 'Stormrage',
                'data' => [
                    'dungeons_done' => [
                        ['level' => 18, 'dungeon' => 401],
                        ['level' => 22, 'dungeon' => 402],
                    ],
                    'world_quests_done' => 30,
                    'vault_options' => [
                        'raids' => ['option_1' => 226, 'option_2' => 226, 'option_3' => 226],
                        'dungeons' => ['option_1' => 226, 'option_2' => 213, 'option_3' => null],
                        'world' => ['option_1' => 220, 'option_2' => null, 'option_3' => null],
                    ],
                ],
            ],
            [
                'id' => 2,
                'name' => 'Tute',
                'realm' => 'Stormrage',
                'data' => [
                    'dungeons_done' => [],
                    'world_quests_done' => 12,
                    'vault_options' => [
                        'raids' => ['option_1' => null, 'option_2' => null, 'option_3' => null],
                        'dungeons' => ['option_1' => null, 'option_2' => null, 'option_3' => null],
                        'world' => ['option_1' => null, 'option_2' => null, 'option_3' => null],
                    ],
                ],
            ],
        ],
    ];
}

function fakeBestGear(int $base = 220): array
{
    $gear = [];
    foreach ([
        'head', 'neck', 'shoulder', 'back', 'chest', 'wrist', 'hands',
        'waist', 'legs', 'feet', 'finger_1', 'finger_2', 'trinket_1',
        'trinket_2', 'main_hand', 'off_hand',
    ] as $slot) {
        $gear[$slot] = ['ilvl' => $base, 'id' => 1, 'name' => 'item', 'quality' => 4];
    }
    return $gear;
}

it('importer pulls period + characters + historical + best_gear', function () {
    fakeMember('Sheday-Stormrage');
    fakeMember('Tute-Stormrage');

    Http::fake([
        'wowaudit.test/v1/period' => Http::response(fakePeriodResponse(1024), 200),
        'wowaudit.test/v1/characters' => Http::response(fakeCharactersResponse(), 200),
        'wowaudit.test/v1/historical_data?period=1024' => Http::response(fakeHistoricalResponse(1024), 200),
        'wowaudit.test/v1/historical_data/1' => Http::response(['character' => ['id' => 1], 'history' => [], 'best_gear' => fakeBestGear(225)], 200),
        'wowaudit.test/v1/historical_data/2' => Http::response(['character' => ['id' => 2], 'history' => [], 'best_gear' => fakeBestGear(210)], 200),
    ]);

    $importer = new WowauditSnapshotImporter(WowauditClient::fromConfig(), 'Regenesis-Silvermoon');
    $result = $importer->pullCurrentPeriod();

    expect($result['period'])->toBe(1024);
    expect($result['characters_returned'])->toBe(2);
    expect($result['matched'])->toBe(2);
    expect($result['skipped'])->toBe(0);

    $snapshot = Snapshot::query()->where('source', Snapshot::SOURCE_WOWAUDIT)->first();
    expect($snapshot)->not->toBeNull();

    $snapshots = MemberSnapshot::query()->where('snapshot_id', $snapshot->id)->get()->keyBy('member_id');
    expect($snapshots)->toHaveCount(2);

    $shedayMember = Member::query()->where('name', 'Sheday-Stormrage')->first();
    $shedaySnap = $snapshots->get($shedayMember->id);
    expect($shedaySnap->ilvl)->toBe(225);
    expect($shedaySnap->mplus_keystone)->toBe(22);
    expect($shedaySnap->vault_progress_json)->toMatchArray([
        'raids' => ['option_1' => 226, 'option_2' => 226, 'option_3' => 226],
    ]);
});

it('skips wowaudit chars with no GRM match', function () {
    Http::fake([
        'wowaudit.test/v1/period' => Http::response(fakePeriodResponse(), 200),
        'wowaudit.test/v1/characters' => Http::response(fakeCharactersResponse(), 200),
        'wowaudit.test/v1/historical_data?period=1024' => Http::response(fakeHistoricalResponse(), 200),
        'wowaudit.test/v1/historical_data/*' => Http::response(['character' => ['id' => 1], 'history' => [], 'best_gear' => fakeBestGear()], 200),
    ]);

    $importer = new WowauditSnapshotImporter(WowauditClient::fromConfig(), 'Regenesis-Silvermoon');
    $result = $importer->pullCurrentPeriod();

    // No members exist; everything skips.
    expect($result['matched'])->toBe(0);
    expect($result['skipped'])->toBe(2);
});

it('matches names with realm spaces and apostrophes (case-insensitive)', function () {
    fakeMember("Argus-Pozzodell'Eternita"); // GRM-style: realm collapsed, no apostrophe

    Http::fake([
        'wowaudit.test/v1/period' => Http::response(fakePeriodResponse(), 200),
        'wowaudit.test/v1/characters' => Http::response([
            ['id' => 99, 'name' => 'Argus', 'realm' => "Pozzo dell'Eternita"],
        ], 200),
        'wowaudit.test/v1/historical_data?period=1024' => Http::response([
            'period' => 1024,
            'characters' => [['id' => 99, 'name' => 'Argus', 'realm' => "Pozzo dell'Eternita", 'data' => ['dungeons_done' => [], 'world_quests_done' => 0, 'vault_options' => []]]],
        ], 200),
        'wowaudit.test/v1/historical_data/99' => Http::response(['best_gear' => fakeBestGear()], 200),
    ]);

    $result = (new WowauditSnapshotImporter(WowauditClient::fromConfig(), 'Regenesis-Silvermoon'))->pullCurrentPeriod();

    expect($result['matched'])->toBe(1);
});

it('command exits clean when WOWAUDIT_API_KEY is empty', function () {
    config(['wowaudit.api_key' => '']);

    $this->artisan('wowaudit:pull')
        ->expectsOutput('WOWAUDIT_API_KEY not set; skipping.')
        ->assertExitCode(0);
});

it('equipped ilvl is the average across all configured slots', function () {
    fakeMember('Sheday-Stormrage');

    // Mixed ilvls per slot - average should be 215 ((220*8 + 210*8)/16 = 215)
    $gear = [];
    $i = 0;
    foreach (['head', 'neck', 'shoulder', 'back', 'chest', 'wrist', 'hands',
              'waist', 'legs', 'feet', 'finger_1', 'finger_2', 'trinket_1',
              'trinket_2', 'main_hand', 'off_hand'] as $slot) {
        $gear[$slot] = ['ilvl' => $i++ < 8 ? 220 : 210, 'id' => 1, 'name' => 'x', 'quality' => 4];
    }

    Http::fake([
        'wowaudit.test/v1/period' => Http::response(fakePeriodResponse(), 200),
        'wowaudit.test/v1/characters' => Http::response([['id' => 1, 'name' => 'Sheday', 'realm' => 'Stormrage']], 200),
        'wowaudit.test/v1/historical_data?period=1024' => Http::response([
            'period' => 1024,
            'characters' => [['id' => 1, 'name' => 'Sheday', 'realm' => 'Stormrage', 'data' => ['dungeons_done' => [], 'vault_options' => []]]],
        ], 200),
        'wowaudit.test/v1/historical_data/1' => Http::response(['best_gear' => $gear], 200),
    ]);

    (new WowauditSnapshotImporter(WowauditClient::fromConfig(), 'Regenesis-Silvermoon'))->pullCurrentPeriod();
    $snap = MemberSnapshot::query()->latest('id')->first();
    expect($snap->ilvl)->toBe(215);
});
