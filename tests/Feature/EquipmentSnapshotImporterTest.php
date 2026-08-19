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

/*
 * Dedup-on-write. Each gear blob is tens of KB and gear rarely moves, so
 * re-writing an identical one every half hour is what filled the 3 GB
 * database cap twice. A one-member fixture cannot catch the regression that
 * matters, because the interesting failure is the sweep pinning itself to the
 * same members forever - so these run a two-member roster at limit 1 and watch
 * who gets fetched.
 */

function eqRotatingImporter(): EquipmentSnapshotImporter
{
    return new EquipmentSnapshotImporter(
        client: BlizzardClient::fromConfig(),
        guildKey: 'Regenesis-Silvermoon',
        requestDelayMs: 0,
        minLevel: 70,
        concurrency: 5,
        limit: 1,
    );
}

function eqFetchCount(string $charSlug): int
{
    return collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), "/character/silvermoon/{$charSlug}/equipment"))
        ->count();
}

it('writes no new row when a member is re-fetched with unchanged gear', function () {
    makeEqMember('Sheday-Silvermoon');
    makeEqMember('Tute-Silvermoon');

    Http::fake([
        'oauth.battle.test/token' => Http::response(['access_token' => 'tok', 'expires_in' => 86399], 200),
        'eu.api.blizzard.test/profile/wow/character/silvermoon/sheday/equipment*' => Http::response(eqPayload(282), 200),
        'eu.api.blizzard.test/profile/wow/character/silvermoon/tute/equipment*' => Http::response(eqPayload(290), 200),
    ]);

    eqRotatingImporter()->pull();                       // Sheday
    $this->travel(1)->minutes();
    eqRotatingImporter()->pull();                       // Tute
    $this->travel(1)->minutes();
    expect(MemberEquipmentSnapshot::query()->count())->toBe(2);

    $third = eqRotatingImporter()->pull();              // Sheday again, unchanged

    expect($third['unchanged'])->toBe(1);
    expect(MemberEquipmentSnapshot::query()->count())->toBe(2);
});

it('keeps rotating through the roster when it skips a write', function () {
    makeEqMember('Sheday-Silvermoon');
    makeEqMember('Tute-Silvermoon');

    Http::fake([
        'oauth.battle.test/token' => Http::response(['access_token' => 'tok', 'expires_in' => 86399], 200),
        'eu.api.blizzard.test/profile/wow/character/silvermoon/sheday/equipment*' => Http::response(eqPayload(282), 200),
        'eu.api.blizzard.test/profile/wow/character/silvermoon/tute/equipment*' => Http::response(eqPayload(290), 200),
    ]);

    for ($run = 0; $run < 6; $run++) {
        eqRotatingImporter()->pull();
        $this->travel(1)->minutes();
    }

    // Six runs, two members, one member per run. A skipped write that left the
    // row on its old snapshot would leave that member permanently the stalest
    // and every run would fetch them: 6 and 0 instead of 3 and 3.
    expect(eqFetchCount('sheday'))->toBe(3);
    expect(eqFetchCount('tute'))->toBe(3);
});

it('writes a new row when the gear actually changes', function () {
    $member = makeEqMember('Sheday-Silvermoon');

    // One sequence, not two Http::fake() calls: a second fake() appends to the
    // stub list rather than replacing it, so the first stub keeps answering
    // and the "changed" run silently gets the old gear back.
    Http::fake([
        'oauth.battle.test/token' => Http::response(['access_token' => 'tok', 'expires_in' => 86399], 200),
        'eu.api.blizzard.test/profile/wow/character/silvermoon/sheday/equipment*' => Http::sequence()
            ->push(eqPayload(282), 200)
            ->push(eqPayload(295), 200),
    ]);

    eqRotatingImporter()->pull();
    $this->travel(1)->minutes();
    $second = eqRotatingImporter()->pull();

    expect($second['unchanged'])->toBe(0);

    $rows = MemberEquipmentSnapshot::query()->where('member_id', $member->id)->orderBy('id')->get();
    expect($rows)->toHaveCount(2);
    expect($rows->last()->equipped_ilvl)->toBe(295);
});
