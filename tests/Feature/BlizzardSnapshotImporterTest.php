<?php

use App\Models\Member;
use App\Models\MemberSnapshot;
use App\Models\Snapshot;
use App\Services\Blizzard\BlizzardClient;
use App\Services\Blizzard\BlizzardSnapshotImporter;
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
        'blizzard.locale' => 'en_GB',
        'blizzard.timeout' => 5,
        'blizzard.token_cache_ttl' => 60,
        'raiderio.realm_slugs' => [
            'TwistingNether' => 'twisting-nether',
            'PozzodellEternita' => 'pozzo-delleternita',
        ],
        'raiderio.default_realm_slug' => 'silvermoon',
        'raiderio.stale_ilvl_window_days' => 90,
        'grm.guild_key' => 'Regenesis-Silvermoon',
    ]);
    Cache::flush();
});

function makeBnetMember(string $name, array $overrides = []): Member
{
    return Member::query()->create(array_replace([
        'guild_key' => 'Regenesis-Silvermoon',
        'name' => $name,
        'class' => 'PRIEST',
        'level' => 80,
        'rank_index' => 5,
        'status' => Member::STATUS_ACTIVE,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'last_online_at' => now(),
    ], $overrides));
}

function bnetProfile(array $overrides = []): array
{
    return array_replace_recursive([
        'name' => 'Sheday',
        'realm' => ['slug' => 'silvermoon', 'name' => 'Silvermoon'],
        'level' => 80,
        'equipped_item_level' => 282,
        'average_item_level' => 285,
        'last_login_timestamp' => now()->getTimestampMs(),
        'faction' => ['type' => 'ALLIANCE'],
    ], $overrides);
}

function fakeBnetOauth(string $token = 'test-token'): void
{
    Http::fake([
        'oauth.battle.test/token' => Http::response(
            ['access_token' => $token, 'expires_in' => 86399],
            200,
        ),
    ]);
}

it('importer pulls profile per active member and writes a snapshot', function () {
    makeBnetMember('Sheday-Silvermoon');
    makeBnetMember('Tute-TwistingNether');

    Http::fake([
        'oauth.battle.test/token' => Http::response(['access_token' => 'tok', 'expires_in' => 86399], 200),
        'eu.api.blizzard.test/profile/wow/character/silvermoon/sheday*' => Http::response(bnetProfile([
            'name' => 'Sheday',
            'equipped_item_level' => 282,
        ]), 200),
        'eu.api.blizzard.test/profile/wow/character/twisting-nether/tute*' => Http::response(bnetProfile([
            'name' => 'Tute',
            'equipped_item_level' => 278,
        ]), 200),
    ]);

    $importer = new BlizzardSnapshotImporter(
        client: BlizzardClient::fromConfig(),
        guildKey: 'Regenesis-Silvermoon',
        requestDelayMs: 0,
    );
    $result = $importer->pull();

    expect($result['members_queried'])->toBe(2);
    expect($result['matched'])->toBe(2);
    expect($result['missing'])->toBe(0);

    $snapshot = Snapshot::query()->where('source', Snapshot::SOURCE_BLIZZARD)->first();
    expect($snapshot)->not->toBeNull();
    expect($snapshot->member_count)->toBe(2);

    $snaps = MemberSnapshot::query()
        ->where('snapshot_id', $snapshot->id)
        ->get()
        ->keyBy(fn ($s) => $s->member->name);

    expect($snaps['Sheday-Silvermoon']->ilvl)->toBe(282);
    expect($snaps['Tute-TwistingNether']->ilvl)->toBe(278);
});

it('skips inactive members and members below the level floor', function () {
    makeBnetMember('Active-Silvermoon', ['level' => 80, 'status' => Member::STATUS_ACTIVE]);
    makeBnetMember('Departed-Silvermoon', ['level' => 80, 'status' => Member::STATUS_LEFT]);
    makeBnetMember('Lowbie-Silvermoon', ['level' => 30, 'status' => Member::STATUS_ACTIVE]);

    fakeBnetOauth();
    Http::fake([
        'eu.api.blizzard.test/*' => Http::response(bnetProfile(), 200),
    ]);

    $result = (new BlizzardSnapshotImporter(
        client: BlizzardClient::fromConfig(),
        guildKey: 'Regenesis-Silvermoon',
        requestDelayMs: 0,
        minLevel: 70,
    ))->pull();

    expect($result['members_queried'])->toBe(1);
    expect($result['matched'])->toBe(1);
});

it('treats Blizzard 404 as missing rather than an error', function () {
    makeBnetMember('Ghost-Silvermoon');

    fakeBnetOauth();
    Http::fake([
        'eu.api.blizzard.test/*' => Http::response(['code' => 404, 'detail' => 'Not Found'], 404),
    ]);

    $result = (new BlizzardSnapshotImporter(
        client: BlizzardClient::fromConfig(),
        guildKey: 'Regenesis-Silvermoon',
        requestDelayMs: 0,
    ))->pull();

    expect($result['matched'])->toBe(0);
    expect($result['missing'])->toBe(1);
    expect($result['errored'])->toBe(0);

    // Snapshot still gets written, with member_count=0 and no MemberSnapshot rows.
    $snapshot = Snapshot::query()->where('source', Snapshot::SOURCE_BLIZZARD)->first();
    expect($snapshot)->not->toBeNull();
    expect(MemberSnapshot::query()->where('snapshot_id', $snapshot->id)->count())->toBe(0);
});

it('dedupes identical pulls via payload_hash', function () {
    makeBnetMember('Sheday-Silvermoon');

    fakeBnetOauth();
    Http::fake([
        'eu.api.blizzard.test/*' => Http::response(bnetProfile(), 200),
    ]);

    $importer = new BlizzardSnapshotImporter(
        client: BlizzardClient::fromConfig(),
        guildKey: 'Regenesis-Silvermoon',
        requestDelayMs: 0,
    );
    $first = $importer->pull();
    $second = $importer->pull();

    expect($first['snapshot_id'])->toBe($second['snapshot_id']);
    expect(Snapshot::query()->where('source', Snapshot::SOURCE_BLIZZARD)->count())->toBe(1);
});

it('uses the configured realm slug map when calling Blizzard', function () {
    makeBnetMember('Argus-PozzodellEternita');

    fakeBnetOauth();
    Http::fake([
        'eu.api.blizzard.test/*' => Http::response(bnetProfile(), 200),
    ]);

    (new BlizzardSnapshotImporter(
        client: BlizzardClient::fromConfig(),
        guildKey: 'Regenesis-Silvermoon',
        requestDelayMs: 0,
    ))->pull();

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/profile/wow/character/pozzo-delleternita/argus'));
});

it('drops ilvl for parked alts whose last login is outside the recency window', function () {
    config(['raiderio.stale_ilvl_window_days' => 90]);
    makeBnetMember('Parked-Silvermoon', ['last_online_at' => now()->subDays(120)]);

    fakeBnetOauth();
    Http::fake([
        'eu.api.blizzard.test/*' => Http::response(bnetProfile([
            'equipped_item_level' => 739,
            'last_login_timestamp' => now()->subDays(120)->getTimestampMs(),
        ]), 200),
    ]);

    (new BlizzardSnapshotImporter(
        client: BlizzardClient::fromConfig(),
        guildKey: 'Regenesis-Silvermoon',
        requestDelayMs: 0,
    ))->pull();

    expect(MemberSnapshot::query()->first()->ilvl)->toBeNull();
});

it('drops ilvl when Blizzard last_login_timestamp is outside the window even if the char is active in GRM', function () {
    // Mirrors the RIO importer's "char is active but the source has
    // stale gear" case. Defensive: both signals must agree.
    config(['raiderio.stale_ilvl_window_days' => 90]);
    makeBnetMember('Refresh-Silvermoon', ['last_online_at' => now()->subDays(10)]);

    fakeBnetOauth();
    Http::fake([
        'eu.api.blizzard.test/*' => Http::response(bnetProfile([
            'equipped_item_level' => 497,
            'last_login_timestamp' => now()->subDays(200)->getTimestampMs(),
        ]), 200),
    ]);

    (new BlizzardSnapshotImporter(
        client: BlizzardClient::fromConfig(),
        guildKey: 'Regenesis-Silvermoon',
        requestDelayMs: 0,
    ))->pull();

    expect(MemberSnapshot::query()->first()->ilvl)->toBeNull();
});

it('keeps ilvl when both GRM and Blizzard signals are inside the window', function () {
    config(['raiderio.stale_ilvl_window_days' => 90]);
    makeBnetMember('Active-Silvermoon', ['last_online_at' => now()->subDays(30)]);

    fakeBnetOauth();
    Http::fake([
        'eu.api.blizzard.test/*' => Http::response(bnetProfile([
            'equipped_item_level' => 282,
            'last_login_timestamp' => now()->subDays(20)->getTimestampMs(),
        ]), 200),
    ]);

    (new BlizzardSnapshotImporter(
        client: BlizzardClient::fromConfig(),
        guildKey: 'Regenesis-Silvermoon',
        requestDelayMs: 0,
    ))->pull();

    expect(MemberSnapshot::query()->first()->ilvl)->toBe(282);
});

it('drops ilvl when Blizzard payload has no last_login_timestamp', function () {
    config(['raiderio.stale_ilvl_window_days' => 90]);
    makeBnetMember('Silent-Silvermoon', ['last_online_at' => now()->subDays(10)]);

    fakeBnetOauth();
    Http::fake([
        'eu.api.blizzard.test/*' => Http::response(bnetProfile([
            'equipped_item_level' => 282,
            'last_login_timestamp' => null,
        ]), 200),
    ]);

    (new BlizzardSnapshotImporter(
        client: BlizzardClient::fromConfig(),
        guildKey: 'Regenesis-Silvermoon',
        requestDelayMs: 0,
    ))->pull();

    expect(MemberSnapshot::query()->first()->ilvl)->toBeNull();
});

it('skips the recency gate entirely when window is set to 0', function () {
    config(['raiderio.stale_ilvl_window_days' => 0]);
    makeBnetMember('Ancient-Silvermoon', ['last_online_at' => now()->subYears(2)]);

    fakeBnetOauth();
    Http::fake([
        'eu.api.blizzard.test/*' => Http::response(bnetProfile([
            'equipped_item_level' => 282,
            'last_login_timestamp' => now()->subYears(2)->getTimestampMs(),
        ]), 200),
    ]);

    (new BlizzardSnapshotImporter(
        client: BlizzardClient::fromConfig(),
        guildKey: 'Regenesis-Silvermoon',
        requestDelayMs: 0,
    ))->pull();

    expect(MemberSnapshot::query()->first()->ilvl)->toBe(282);
});

it('throws when Blizzard credentials are not configured', function () {
    config(['blizzard.client_id' => '', 'blizzard.client_secret' => '']);
    makeBnetMember('Sheday-Silvermoon');

    expect(fn () => (new BlizzardSnapshotImporter(
        client: BlizzardClient::fromConfig(),
        guildKey: 'Regenesis-Silvermoon',
    ))->pull())
        ->toThrow(\RuntimeException::class, 'Blizzard client credentials are not configured');
});

it('blizzard:pull short-circuits cleanly when credentials are not configured', function () {
    config(['blizzard.client_id' => '', 'blizzard.client_secret' => '']);

    $this->artisan('blizzard:pull')
        ->expectsOutputToContain('blizzard:pull skipped')
        ->assertExitCode(0);
});

it('blizzard:pull runs end-to-end with no active members', function () {
    $this->artisan('blizzard:pull')
        ->expectsOutputToContain('0 members queried')
        ->assertExitCode(0);
});
