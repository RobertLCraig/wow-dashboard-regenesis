<?php

use App\Models\Member;
use App\Models\MemberEquipmentSnapshot;
use App\Models\Snapshot;
use App\Services\Blizzard\BlizzardClient;
use App\Services\Blizzard\EquipmentSnapshotImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'blizzard.region' => 'eu',
        'blizzard.client_id' => 'test-client-id',
        'blizzard.client_secret' => 'test-client-secret',
        'blizzard.api_base_url' => 'https://eu.api.blizzard.test',
        'blizzard.oauth_token_url' => 'https://oauth.battle.test/token',
        'blizzard.namespace' => 'profile-eu',
        'blizzard.dynamic_namespace' => 'dynamic-eu',
        'blizzard.locale' => 'en_GB',
        'blizzard.timeout' => 5,
        'blizzard.token_cache_ttl' => 60,
        'raiderio.realm_slugs' => [
            'TwistingNether' => 'twisting-nether',
        ],
        'raiderio.default_realm_slug' => 'silvermoon',
        'grm.guild_key' => 'Regenesis-Silvermoon',
    ]);
    Cache::flush();
});

function makeEqMember(string $name, array $overrides = []): Member
{
    return Member::query()->create(array_replace([
        'guild_key' => 'Regenesis-Silvermoon',
        'name' => $name,
        'class' => 'PRIEST',
        'level' => 80,
        'rank_index' => 5,
        'realm_slug' => 'silvermoon',
        'status' => Member::STATUS_ACTIVE,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'last_online_at' => now(),
    ], $overrides));
}

function eqPayload(int $equipped = 282, int $average = 285): array
{
    return [
        'equipped_item_level' => $equipped,
        'average_item_level' => $average,
        'equipped_items' => [
            [
                'item' => ['id' => 100001],
                'slot' => ['type' => 'HEAD', 'name' => 'Head'],
                'level' => ['value' => $equipped],
                'enchantments' => [],
                'sockets' => [],
            ],
            [
                'item' => ['id' => 100002],
                'slot' => ['type' => 'CHEST', 'name' => 'Chest'],
                'level' => ['value' => $equipped],
                'enchantments' => [['enchantment_id' => 9999]],
                'sockets' => [['item' => ['id' => 213743]]],
            ],
        ],
    ];
}

function makeEqImporter(int $minLevel = 70): EquipmentSnapshotImporter
{
    return new EquipmentSnapshotImporter(
        client: BlizzardClient::fromConfig(),
        guildKey: 'Regenesis-Silvermoon',
        requestDelayMs: 0,
        minLevel: $minLevel,
        concurrency: 5,
    );
}

it('hits /equipment for every active member at or above the level floor', function () {
    makeEqMember('Sheday-Silvermoon');
    makeEqMember('Tute-Silvermoon');
    makeEqMember('Twink-Silvermoon', ['level' => 30]); // below floor

    Http::fake([
        'oauth.battle.test/token' => Http::response(['access_token' => 'tok', 'expires_in' => 86399], 200),
        'eu.api.blizzard.test/profile/wow/character/silvermoon/sheday/equipment*' => Http::response(eqPayload(282), 200),
        'eu.api.blizzard.test/profile/wow/character/silvermoon/tute/equipment*' => Http::response(eqPayload(290), 200),
    ]);

    $result = makeEqImporter()->pull();

    expect($result['matched'])->toBe(2);
    expect($result['missing'])->toBe(0);
    expect($result['errored'])->toBe(0);
    expect($result['members_queried'])->toBe(2);

    $rows = MemberEquipmentSnapshot::query()->get();
    expect($rows)->toHaveCount(2);
    expect($rows->where('equipped_ilvl', 282)->first()?->pieces)->toBeArray();
});

it('stamps a snapshot row with source=blizzard_equipment and dedupes on payload hash', function () {
    makeEqMember('Sheday-Silvermoon');

    Http::fake([
        'oauth.battle.test/token' => Http::response(['access_token' => 'tok', 'expires_in' => 86399], 200),
        'eu.api.blizzard.test/profile/wow/character/silvermoon/sheday/equipment*' => Http::response(eqPayload(282), 200),
    ]);

    $first = makeEqImporter()->pull();
    $second = makeEqImporter()->pull();

    expect($first['snapshot_id'])->toBe($second['snapshot_id']);

    $snapshots = Snapshot::query()
        ->where('source', Snapshot::SOURCE_BLIZZARD_EQUIPMENT)
        ->get();
    expect($snapshots)->toHaveCount(1);
});

it('treats 404 as missing not error', function () {
    makeEqMember('Sheday-Silvermoon');
    makeEqMember('Ghost-Silvermoon');

    Http::fake([
        'oauth.battle.test/token' => Http::response(['access_token' => 'tok', 'expires_in' => 86399], 200),
        'eu.api.blizzard.test/profile/wow/character/silvermoon/sheday/equipment*' => Http::response(eqPayload(282), 200),
        'eu.api.blizzard.test/profile/wow/character/silvermoon/ghost/equipment*' => Http::response(['code' => 404], 404),
    ]);

    $result = makeEqImporter()->pull();

    expect($result['matched'])->toBe(1);
    expect($result['missing'])->toBe(1);
    expect($result['errored'])->toBe(0);
});

it('falls back to the legacy realm-slug derivation when realm_slug is null', function () {
    makeEqMember('Argus-TwistingNether', ['realm_slug' => null, 'realm' => null]);

    Http::fake([
        'oauth.battle.test/token' => Http::response(['access_token' => 'tok', 'expires_in' => 86399], 200),
        'eu.api.blizzard.test/profile/wow/character/twisting-nether/argus/equipment*' => Http::response(eqPayload(280), 200),
    ]);

    $result = makeEqImporter()->pull();

    expect($result['matched'])->toBe(1);
    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/profile/wow/character/twisting-nether/argus/equipment'));
});

it('throws when credentials are missing', function () {
    config(['blizzard.client_id' => '', 'blizzard.client_secret' => '']);
    makeEqMember('Sheday-Silvermoon');

    expect(fn () => makeEqImporter()->pull())
        ->toThrow(\RuntimeException::class, 'not configured');
});
