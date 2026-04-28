<?php

use App\Models\Member;
use App\Models\MemberMplusSnapshot;
use App\Models\Snapshot;
use App\Services\Blizzard\BlizzardClient;
use App\Services\Blizzard\MplusSnapshotImporter;
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

function makeMplusMember(string $name, array $overrides = []): Member
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

function mplusPayload(float $rating = 2456.7, array $bestRuns = []): array
{
    return [
        'current_mythic_rating' => [
            'rating' => $rating,
            'color' => ['r' => 255, 'g' => 128, 'b' => 0, 'a' => 1.0],
        ],
        'current_period' => [
            'period' => ['id' => 990],
            'best_runs' => $bestRuns ?: [
                ['dungeon' => ['name' => 'Test'], 'keystone_level' => 12, 'mythic_rating' => ['rating' => 200.5]],
            ],
        ],
        'seasons' => [
            ['id' => 14, 'season' => ['key' => ['href' => 'x']]],
        ],
    ];
}

function makeMplusImporter(): MplusSnapshotImporter
{
    return new MplusSnapshotImporter(
        client: BlizzardClient::fromConfig(),
        guildKey: 'Regenesis-Silvermoon',
        requestDelayMs: 0,
        minLevel: 70,
        concurrency: 5,
    );
}

it('hits /mythic-keystone-profile and stores rating + runs', function () {
    makeMplusMember('Sheday-Silvermoon');

    Http::fake([
        'oauth.battle.test/token' => Http::response(['access_token' => 'tok', 'expires_in' => 86399], 200),
        'eu.api.blizzard.test/profile/wow/character/silvermoon/sheday/mythic-keystone-profile*' => Http::response(mplusPayload(2456.7), 200),
    ]);

    $result = makeMplusImporter()->pull();

    expect($result['matched'])->toBe(1);

    $row = MemberMplusSnapshot::query()->firstOrFail();
    expect($row->mythic_rating)->toBe(2456.7);
    expect($row->current_period_runs)->toBeArray();
    expect($row->seasons)->toBeArray();

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/profile/wow/character/silvermoon/sheday/mythic-keystone-profile')
        && $req->hasHeader('Battlenet-Namespace', 'profile-eu'));
});

it('treats 404 as missing for chars who have not done a key', function () {
    makeMplusMember('Sheday-Silvermoon');
    makeMplusMember('Newbie-Silvermoon');

    Http::fake([
        'oauth.battle.test/token' => Http::response(['access_token' => 'tok', 'expires_in' => 86399], 200),
        'eu.api.blizzard.test/profile/wow/character/silvermoon/sheday/mythic-keystone-profile*' => Http::response(mplusPayload(), 200),
        'eu.api.blizzard.test/profile/wow/character/silvermoon/newbie/mythic-keystone-profile*' => Http::response(['code' => 404], 404),
    ]);

    $result = makeMplusImporter()->pull();

    expect($result['matched'])->toBe(1);
    expect($result['missing'])->toBe(1);
    expect($result['errored'])->toBe(0);
});

it('stamps the snapshot with source=blizzard_mplus and dedupes', function () {
    makeMplusMember('Sheday-Silvermoon');

    Http::fake([
        'oauth.battle.test/token' => Http::response(['access_token' => 'tok', 'expires_in' => 86399], 200),
        'eu.api.blizzard.test/*' => Http::response(mplusPayload(), 200),
    ]);

    $first = makeMplusImporter()->pull();
    $second = makeMplusImporter()->pull();

    expect($first['snapshot_id'])->toBe($second['snapshot_id']);
    expect(Snapshot::query()->where('source', Snapshot::SOURCE_BLIZZARD_MPLUS)->count())->toBe(1);
});

it('handles a payload with no current_mythic_rating gracefully', function () {
    makeMplusMember('Sheday-Silvermoon');

    Http::fake([
        'oauth.battle.test/token' => Http::response(['access_token' => 'tok', 'expires_in' => 86399], 200),
        'eu.api.blizzard.test/*' => Http::response([
            'current_period' => ['best_runs' => []],
            'seasons' => [],
        ], 200),
    ]);

    $result = makeMplusImporter()->pull();

    expect($result['matched'])->toBe(1);
    $row = MemberMplusSnapshot::query()->firstOrFail();
    expect($row->mythic_rating)->toBeNull();
});

it('throws when credentials are missing', function () {
    config(['blizzard.client_id' => '', 'blizzard.client_secret' => '']);
    makeMplusMember('Sheday-Silvermoon');

    expect(fn () => makeMplusImporter()->pull())
        ->toThrow(\RuntimeException::class, 'not configured');
});
