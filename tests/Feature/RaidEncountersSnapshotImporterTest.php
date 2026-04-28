<?php

use App\Models\Member;
use App\Models\MemberRaidSnapshot;
use App\Models\Snapshot;
use App\Services\Blizzard\BlizzardClient;
use App\Services\Blizzard\RaidEncountersSnapshotImporter;
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
        'raiderio.realm_slugs' => [],
        'raiderio.default_realm_slug' => 'silvermoon',
        'grm.guild_key' => 'Regenesis-Silvermoon',
    ]);
    Cache::flush();
});

function makeRaidMember(string $name, array $overrides = []): Member
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

function raidsPayload(): array
{
    return [
        'expansions' => [
            [
                'expansion' => ['name' => 'The War Within', 'id' => 503],
                'instances' => [
                    [
                        'instance' => ['name' => 'Manaforge Omega', 'id' => 1296],
                        'modes' => [
                            [
                                'difficulty' => ['type' => 'HEROIC', 'name' => 'Heroic'],
                                'status' => ['type' => 'COMPLETE'],
                                'progress' => [
                                    'completed_count' => 8,
                                    'total_count' => 8,
                                    'encounters' => [],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

function makeRaidsImporter(): RaidEncountersSnapshotImporter
{
    return new RaidEncountersSnapshotImporter(
        client: BlizzardClient::fromConfig(),
        guildKey: 'Regenesis-Silvermoon',
        requestDelayMs: 0,
        minLevel: 70,
        concurrency: 5,
    );
}

it('hits /encounters/raids and stores the expansions tree', function () {
    makeRaidMember('Sheday-Silvermoon');

    Http::fake([
        'oauth.battle.test/token' => Http::response(['access_token' => 'tok', 'expires_in' => 86399], 200),
        'eu.api.blizzard.test/profile/wow/character/silvermoon/sheday/encounters/raids*' => Http::response(raidsPayload(), 200),
    ]);

    $result = makeRaidsImporter()->pull();

    expect($result['matched'])->toBe(1);

    $row = MemberRaidSnapshot::query()->firstOrFail();
    expect($row->expansions)->toBeArray();
    expect($row->expansions[0]['expansion']['name'])->toBe('The War Within');
    expect($row->expansions[0]['instances'][0]['modes'][0]['difficulty']['type'])->toBe('HEROIC');
});

it('treats 404 as missing for chars who have not zoned a raid', function () {
    makeRaidMember('Sheday-Silvermoon');
    makeRaidMember('Newbie-Silvermoon');

    Http::fake([
        'oauth.battle.test/token' => Http::response(['access_token' => 'tok', 'expires_in' => 86399], 200),
        'eu.api.blizzard.test/profile/wow/character/silvermoon/sheday/encounters/raids*' => Http::response(raidsPayload(), 200),
        'eu.api.blizzard.test/profile/wow/character/silvermoon/newbie/encounters/raids*' => Http::response(['code' => 404], 404),
    ]);

    $result = makeRaidsImporter()->pull();

    expect($result['matched'])->toBe(1);
    expect($result['missing'])->toBe(1);
    expect($result['errored'])->toBe(0);
});

it('stamps the snapshot with source=blizzard_raids and dedupes', function () {
    makeRaidMember('Sheday-Silvermoon');

    Http::fake([
        'oauth.battle.test/token' => Http::response(['access_token' => 'tok', 'expires_in' => 86399], 200),
        'eu.api.blizzard.test/*' => Http::response(raidsPayload(), 200),
    ]);

    $first = makeRaidsImporter()->pull();
    $second = makeRaidsImporter()->pull();

    expect($first['snapshot_id'])->toBe($second['snapshot_id']);
    expect(Snapshot::query()->where('source', Snapshot::SOURCE_BLIZZARD_RAIDS)->count())->toBe(1);
});

it('throws when credentials are missing', function () {
    config(['blizzard.client_id' => '', 'blizzard.client_secret' => '']);
    makeRaidMember('Sheday-Silvermoon');

    expect(fn () => makeRaidsImporter()->pull())
        ->toThrow(\RuntimeException::class, 'not configured');
});
