<?php

use App\Models\Member;
use App\Models\MemberSocialSnapshot;
use App\Models\Snapshot;
use App\Services\Blizzard\BlizzardClient;
use App\Services\Blizzard\SocialSnapshotImporter;
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

function makeSocialMember(string $name, array $overrides = []): Member
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

function fakeAllSocialEndpoints(string $charSlug): void
{
    Http::fake([
        'oauth.battle.test/token' => Http::response(['access_token' => 'tok', 'expires_in' => 86399], 200),
        "eu.api.blizzard.test/profile/wow/character/silvermoon/{$charSlug}/character-media*" => Http::response([
            'avatar_url' => "https://render.test/avatar-{$charSlug}.jpg",
            'inset_url' => "https://render.test/inset-{$charSlug}.jpg",
            'main_url' => "https://render.test/main-{$charSlug}.jpg",
        ], 200),
        "eu.api.blizzard.test/profile/wow/character/silvermoon/{$charSlug}/achievements*" => Http::response([
            'total_quantity' => 1234,
            'total_points' => 23456,
            'achievements' => [],
        ], 200),
        "eu.api.blizzard.test/profile/wow/character/silvermoon/{$charSlug}/collections/mounts*" => Http::response([
            'mounts' => array_fill(0, 412, ['mount' => ['id' => 1]]),
        ], 200),
        "eu.api.blizzard.test/profile/wow/character/silvermoon/{$charSlug}/collections/pets*" => Http::response([
            'total_quantity_collected' => 891,
            'pets' => array_fill(0, 891, ['species' => ['id' => 1]]),
        ], 200),
        "eu.api.blizzard.test/profile/wow/character/silvermoon/{$charSlug}/collections/toys*" => Http::response([
            'toys' => array_fill(0, 234, ['toy' => ['id' => 1]]),
        ], 200),
        "eu.api.blizzard.test/profile/wow/character/silvermoon/{$charSlug}/collections/transmogs*" => Http::response([
            'appearance_sets' => [],
            'slots' => [],
        ], 200),
    ]);
}

function makeSocialImporter(): SocialSnapshotImporter
{
    return new SocialSnapshotImporter(
        client: BlizzardClient::fromConfig(),
        guildKey: 'Regenesis-Silvermoon',
        requestDelayMs: 0,
        minLevel: 70,
        concurrency: 5,
    );
}

it('pulls all six endpoints per member and stores them with denormalised counts', function () {
    makeSocialMember('Sheday-Silvermoon');
    fakeAllSocialEndpoints('sheday');

    $result = makeSocialImporter()->pull();

    expect($result['matched'])->toBe(1);
    expect($result['missing'])->toBe(0);

    $row = MemberSocialSnapshot::query()->firstOrFail();
    expect($row->character_media['avatar_url'])->toBe('https://render.test/avatar-sheday.jpg');
    expect($row->achievement_points)->toBe(23456);
    expect($row->total_mounts)->toBe(412);
    expect($row->total_pets)->toBe(891);
    expect($row->total_toys)->toBe(234);
});

it('treats per-endpoint 404 as a soft miss without poisoning the others', function () {
    makeSocialMember('Sheday-Silvermoon');

    Http::fake([
        'oauth.battle.test/token' => Http::response(['access_token' => 'tok', 'expires_in' => 86399], 200),
        'eu.api.blizzard.test/profile/wow/character/silvermoon/sheday/character-media*' => Http::response(['avatar_url' => 'https://x'], 200),
        'eu.api.blizzard.test/profile/wow/character/silvermoon/sheday/achievements*' => Http::response(['total_points' => 100, 'achievements' => []], 200),
        'eu.api.blizzard.test/profile/wow/character/silvermoon/sheday/collections/mounts*' => Http::response(['mounts' => []], 200),
        'eu.api.blizzard.test/profile/wow/character/silvermoon/sheday/collections/pets*' => Http::response(['code' => 404], 404),
        'eu.api.blizzard.test/profile/wow/character/silvermoon/sheday/collections/toys*' => Http::response(['toys' => []], 200),
        'eu.api.blizzard.test/profile/wow/character/silvermoon/sheday/collections/transmogs*' => Http::response(['code' => 404], 404),
    ]);

    $result = makeSocialImporter()->pull();

    expect($result['matched'])->toBe(1);

    $row = MemberSocialSnapshot::query()->firstOrFail();
    expect($row->achievement_points)->toBe(100);
    expect($row->pets)->toBeNull();
    expect($row->transmogs)->toBeNull();
    expect($row->achievements)->toBeArray();
});

it('counts a member with all 404s as missing', function () {
    makeSocialMember('Ghost-Silvermoon');

    Http::fake([
        'oauth.battle.test/token' => Http::response(['access_token' => 'tok', 'expires_in' => 86399], 200),
        'eu.api.blizzard.test/profile/wow/character/silvermoon/ghost/*' => Http::response(['code' => 404], 404),
    ]);

    $result = makeSocialImporter()->pull();

    expect($result['matched'])->toBe(0);
    expect($result['missing'])->toBe(1);
});

it('stamps the snapshot with source=blizzard_social and writes one row per pull', function () {
    makeSocialMember('Sheday-Silvermoon');
    fakeAllSocialEndpoints('sheday');

    $first = makeSocialImporter()->pull();
    $second = makeSocialImporter()->pull();

    // Dedupe-by-payload-hash was dropped when the importer moved to
    // chunked incremental persistence (see SocialSnapshotImporter
    // class doc). Each pull now writes its own snapshot row.
    expect($first['snapshot_id'])->not->toBe($second['snapshot_id']);
    expect(Snapshot::query()->where('source', Snapshot::SOURCE_BLIZZARD_SOCIAL)->count())->toBe(2);
});

it('throws when credentials are missing', function () {
    config(['blizzard.client_id' => '', 'blizzard.client_secret' => '']);
    makeSocialMember('Sheday-Silvermoon');

    expect(fn () => makeSocialImporter()->pull())
        ->toThrow(\RuntimeException::class, 'not configured');
});
