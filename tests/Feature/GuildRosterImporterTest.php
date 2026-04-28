<?php

use App\Models\Member;
use App\Services\Blizzard\BlizzardClient;
use App\Services\Blizzard\GuildRosterImporter;
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
        'blizzard.guild_realm_slug' => 'silvermoon',
        'blizzard.guild_name_slug' => 'regenesis',
        'grm.guild_key' => 'Regenesis-Silvermoon',
    ]);
    Cache::flush();
});

function bnetRosterEntry(array $overrides = []): array
{
    return array_replace_recursive([
        'character' => [
            'name' => 'Sheday',
            'id' => 1234567,
            'realm' => [
                'slug' => 'silvermoon',
                'id' => 1,
                'name' => 'Silvermoon',
            ],
            'level' => 80,
            'playable_class' => ['id' => 5],
            'playable_race' => ['id' => 3],
            'faction' => ['type' => 'ALLIANCE'],
        ],
        'rank' => 4,
    ], $overrides);
}

function bnetRosterFake(array $members): void
{
    Http::fake([
        'oauth.battle.test/token' => Http::response(['access_token' => 'tok', 'expires_in' => 86399], 200),
        'eu.api.blizzard.test/data/wow/guild/*' => Http::response([
            'guild' => ['name' => 'Regenesis'],
            'members' => $members,
        ], 200),
    ]);
}

function importer(): GuildRosterImporter
{
    return new GuildRosterImporter(
        client: BlizzardClient::fromConfig(),
        guildKey: 'Regenesis-Silvermoon',
        guildRealmSlug: 'silvermoon',
        guildNameSlug: 'regenesis',
    );
}

it('hits the dynamic namespace guild roster endpoint', function () {
    bnetRosterFake([bnetRosterEntry()]);

    importer()->pull();

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), 'eu.api.blizzard.test/data/wow/guild/silvermoon/regenesis/roster')
        && $req->hasHeader('Battlenet-Namespace', 'dynamic-eu')
        && $req->hasHeader('Authorization', 'Bearer tok'));
});

it('inserts a brand-new member with realm collapsed in name', function () {
    bnetRosterFake([
        bnetRosterEntry([
            'character' => [
                'name' => 'Argus',
                'id' => 9999,
                'realm' => ['slug' => 'twisting-nether', 'name' => 'Twisting Nether'],
                'level' => 78,
            ],
            'rank' => 6,
        ]),
    ]);

    $result = importer()->pull();

    expect($result['inserted'])->toBe(1);
    expect($result['updated'])->toBe(0);
    expect($result['total_in_roster'])->toBe(1);

    $m = Member::query()->where('blizzard_character_id', 9999)->firstOrFail();
    expect($m->name)->toBe('Argus-TwistingNether');
    expect($m->realm_slug)->toBe('twisting-nether');
    expect($m->level)->toBe(78);
    expect($m->rank_index)->toBe(6);
    expect($m->faction)->toBe('Alliance');
    expect($m->last_blizzard_seen_at)->not()->toBeNull();
    expect($m->is_valid_at_blizzard)->toBeTrue();
    expect($m->status)->toBe(Member::STATUS_ACTIVE);
});

it('updates an existing member matched by name', function () {
    Member::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'name' => 'Sheday-Silvermoon',
        'class' => 'PRIEST',
        'level' => 79,
        'rank_index' => 9,
        'status' => Member::STATUS_ACTIVE,
        'first_seen_at' => now()->subYear(),
        'last_seen_at' => now()->subDay(),
        'custom_note' => 'Officer-curated note must survive',
    ]);

    bnetRosterFake([bnetRosterEntry()]);

    $result = importer()->pull();

    expect($result['inserted'])->toBe(0);
    expect($result['updated'])->toBe(1);

    $m = Member::query()->where('name', 'Sheday-Silvermoon')->firstOrFail();
    expect($m->blizzard_character_id)->toBe(1234567);
    expect($m->realm_slug)->toBe('silvermoon');
    expect($m->level)->toBe(80);
    expect($m->rank_index)->toBe(4);
    expect($m->custom_note)->toBe('Officer-curated note must survive');
});

it('matches an existing member by character id even when name has changed', function () {
    Member::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'name' => 'Oldname-Silvermoon',
        'blizzard_character_id' => 1234567,
        'realm_slug' => 'silvermoon',
        'level' => 80,
        'status' => Member::STATUS_ACTIVE,
        'first_seen_at' => now()->subYear(),
        'last_seen_at' => now(),
    ]);

    bnetRosterFake([bnetRosterEntry([
        'character' => ['name' => 'Newname'],
    ])]);

    $result = importer()->pull();

    expect($result['inserted'])->toBe(0);
    expect($result['updated'])->toBe(1);

    $m = Member::query()->where('blizzard_character_id', 1234567)->firstOrFail();
    expect($m->name)->toBe('Newname-Silvermoon');
});

it('counts members not seen in this pull without flipping their status', function () {
    Member::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'name' => 'Ghost-Silvermoon',
        'blizzard_character_id' => 7777,
        'realm_slug' => 'silvermoon',
        'level' => 80,
        'status' => Member::STATUS_ACTIVE,
        'first_seen_at' => now()->subYear(),
        'last_seen_at' => now()->subDay(),
    ]);

    bnetRosterFake([bnetRosterEntry()]);

    $result = importer()->pull();

    expect($result['not_seen_this_pull'])->toBe(1);
    $ghost = Member::query()->where('blizzard_character_id', 7777)->firstOrFail();
    expect($ghost->status)->toBe(Member::STATUS_ACTIVE);
});

it('skips malformed roster entries without exploding the pull', function () {
    bnetRosterFake([
        bnetRosterEntry(),
        ['character' => ['name' => 'NoId']], // missing id + realm
        ['rank' => 1],                        // no character at all
    ]);

    $result = importer()->pull();

    expect($result['inserted'])->toBe(1);
    expect($result['total_in_roster'])->toBe(3);
});

it('throws when credentials are missing', function () {
    config(['blizzard.client_id' => '', 'blizzard.client_secret' => '']);

    expect(fn () => importer()->pull())
        ->toThrow(\RuntimeException::class, 'not configured');
});

it('throws when guild slugs are missing', function () {
    $imp = new GuildRosterImporter(
        client: BlizzardClient::fromConfig(),
        guildKey: 'Regenesis-Silvermoon',
        guildRealmSlug: '',
        guildNameSlug: '',
    );

    expect(fn () => $imp->pull())
        ->toThrow(\RuntimeException::class, 'guild identity is not configured');
});

it('throws when the roster fetch returns a non-2xx', function () {
    Http::fake([
        'oauth.battle.test/token' => Http::response(['access_token' => 'tok', 'expires_in' => 86399], 200),
        'eu.api.blizzard.test/data/wow/guild/*' => Http::response(['code' => 404, 'detail' => 'Not Found'], 404),
    ]);

    expect(fn () => importer()->pull())
        ->toThrow(\RuntimeException::class, 'guild roster fetch failed: 404');
});
