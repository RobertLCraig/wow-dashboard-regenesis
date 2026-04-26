<?php

use App\Models\Member;
use App\Models\MemberSnapshot;
use App\Models\Snapshot;
use App\Services\Raiderio\RaiderioClient;
use App\Services\Raiderio\RaiderioSnapshotImporter;
use App\Services\Raiderio\RealmSlug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'raiderio.region' => 'eu',
        'raiderio.base_url' => 'https://raider.io.test/api/v1',
        'raiderio.profile_fields' => [
            'gear', 'raid_progression',
            'mythic_plus_scores_by_season:current',
            'mythic_plus_weekly_highest_level_runs',
        ],
        'raiderio.request_delay_ms' => 0,
        'raiderio.timeout' => 5,
        'raiderio.default_realm_slug' => 'silvermoon',
        'raiderio.realm_slugs' => [
            'TwistingNether' => 'twisting-nether',
            'PozzodellEternita' => 'pozzo-delleternita',
        ],
        'grm.guild_key' => 'Regenesis-Silvermoon',
    ]);
});

function makeMember(string $name, array $overrides = []): Member
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
    ], $overrides));
}

function rioProfile(array $overrides = []): array
{
    return array_replace_recursive([
        'name' => 'Sheday',
        'realm' => 'Silvermoon',
        'gear' => ['item_level_equipped' => 642.7, 'item_level_total' => 645.3],
        'raid_progression' => [
            'manaforge-omega' => [
                'summary' => '8/8 H',
                'total_bosses' => 8,
                'normal_bosses_killed' => 8,
                'heroic_bosses_killed' => 8,
                'mythic_bosses_killed' => 0,
            ],
        ],
        'mythic_plus_scores_by_season' => [[
            'season' => 'season-tww-3',
            'scores' => ['all' => 1234.5, 'dps' => 1234.5, 'healer' => 0, 'tank' => 0],
        ]],
        'mythic_plus_weekly_highest_level_runs' => [
            ['mythic_level' => 12, 'dungeon' => 'Theatre of Pain'],
            ['mythic_level' => 14, 'dungeon' => 'Halls of Atonement'],
        ],
    ], $overrides);
}

it('parses realm out of a Char-Realm member name', function () {
    expect(RealmSlug::realmFromMemberName('Sheday-Stormrage'))->toBe('Stormrage');
    expect(RealmSlug::realmFromMemberName('Argus-PozzodellEternita'))->toBe('PozzodellEternita');
    expect(RealmSlug::realmFromMemberName('Loner'))->toBeNull();
    expect(RealmSlug::realmFromMemberName('Trailing-'))->toBeNull();
});

it('slugifies realms via map then lowercase fallback', function () {
    expect(RealmSlug::slugify('TwistingNether'))->toBe('twisting-nether');
    expect(RealmSlug::slugify('PozzodellEternita'))->toBe('pozzo-delleternita');
    // Unknown realm -> lowercase fallback (correct for single-word realms)
    expect(RealmSlug::slugify('Stormrage'))->toBe('stormrage');
    // Empty input -> default
    expect(RealmSlug::slugify(null))->toBe('silvermoon');
    expect(RealmSlug::slugify(''))->toBe('silvermoon');
});

it('importer pulls profile per active member and writes a snapshot', function () {
    makeMember('Sheday-Silvermoon');
    makeMember('Tute-TwistingNether');

    Http::fake([
        'raider.io.test/api/v1/characters/profile?*name=Sheday*' => Http::response(rioProfile([
            'name' => 'Sheday', 'realm' => 'Silvermoon',
            'gear' => ['item_level_equipped' => 642.7],
        ]), 200),
        'raider.io.test/api/v1/characters/profile?*name=Tute*' => Http::response(rioProfile([
            'name' => 'Tute', 'realm' => 'Twisting Nether',
            'gear' => ['item_level_equipped' => 638.2],
            'mythic_plus_scores_by_season' => [['scores' => ['all' => 980.0]]],
        ]), 200),
    ]);

    $importer = new RaiderioSnapshotImporter(
        client: RaiderioClient::fromConfig(),
        guildKey: 'Regenesis-Silvermoon',
        requestDelayMs: 0,
    );
    $result = $importer->pull();

    expect($result['members_queried'])->toBe(2);
    expect($result['matched'])->toBe(2);
    expect($result['missing'])->toBe(0);
    expect($result['errored'])->toBe(0);

    $snapshot = Snapshot::query()->where('source', Snapshot::SOURCE_RAIDERIO)->first();
    expect($snapshot)->not->toBeNull();
    expect($snapshot->member_count)->toBe(2);

    $snaps = MemberSnapshot::query()
        ->where('snapshot_id', $snapshot->id)
        ->get()
        ->keyBy(fn ($s) => $s->member->name);

    expect($snaps)->toHaveCount(2);
    expect($snaps['Sheday-Silvermoon']->ilvl)->toBe(643);  // 642.7 rounds to 643
    expect($snaps['Sheday-Silvermoon']->mplus_score)->toBe(1234.5);
    expect($snaps['Sheday-Silvermoon']->mplus_keystone)->toBe(14);
    expect($snaps['Sheday-Silvermoon']->raid_progression_json['manaforge-omega']['summary'])
        ->toBe('8/8 H');
    expect($snaps['Sheday-Silvermoon']->raid_progression_json['manaforge-omega']['heroic_bosses_killed'])
        ->toBe(8);
    expect($snaps['Tute-TwistingNether']->ilvl)->toBe(638);
    expect($snaps['Tute-TwistingNether']->mplus_score)->toBe(980.0);
});

it('skips inactive members and members below the level floor', function () {
    makeMember('Active-Silvermoon', ['level' => 80, 'status' => Member::STATUS_ACTIVE]);
    makeMember('Departed-Silvermoon', ['level' => 80, 'status' => Member::STATUS_LEFT]);
    makeMember('Lowbie-Silvermoon', ['level' => 30, 'status' => Member::STATUS_ACTIVE]);

    Http::fake([
        '*' => Http::response(rioProfile(), 200),
    ]);

    $result = (new RaiderioSnapshotImporter(
        client: RaiderioClient::fromConfig(),
        guildKey: 'Regenesis-Silvermoon',
        requestDelayMs: 0,
        minLevel: 70,
    ))->pull();

    expect($result['members_queried'])->toBe(1);
    expect($result['matched'])->toBe(1);

    Http::assertSentCount(1);
});

it('treats RIO 404 as missing rather than an error', function () {
    makeMember('Ghost-Silvermoon');

    Http::fake([
        'raider.io.test/api/v1/characters/profile?*' => Http::response(['statusCode' => 404, 'error' => 'Not Found'], 404),
    ]);

    $result = (new RaiderioSnapshotImporter(
        client: RaiderioClient::fromConfig(),
        guildKey: 'Regenesis-Silvermoon',
        requestDelayMs: 0,
    ))->pull();

    expect($result['matched'])->toBe(0);
    expect($result['missing'])->toBe(1);
    expect($result['errored'])->toBe(0);

    // Snapshot still gets written, with member_count=0 and no MemberSnapshot rows.
    $snapshot = Snapshot::query()->where('source', Snapshot::SOURCE_RAIDERIO)->first();
    expect($snapshot)->not->toBeNull();
    expect(MemberSnapshot::query()->where('snapshot_id', $snapshot->id)->count())->toBe(0);
});

it('dedupes identical pulls via payload_hash', function () {
    makeMember('Sheday-Silvermoon');

    Http::fake([
        '*' => Http::response(rioProfile(), 200),
    ]);

    $importer = new RaiderioSnapshotImporter(
        client: RaiderioClient::fromConfig(),
        guildKey: 'Regenesis-Silvermoon',
        requestDelayMs: 0,
    );
    $first = $importer->pull();
    $second = $importer->pull();

    expect($first['snapshot_id'])->toBe($second['snapshot_id']);
    expect(Snapshot::query()->where('source', Snapshot::SOURCE_RAIDERIO)->count())->toBe(1);
});

it('uses the configured realm slug map when calling RIO', function () {
    makeMember('Argus-PozzodellEternita');

    Http::fake([
        '*' => Http::response(rioProfile(), 200),
    ]);

    (new RaiderioSnapshotImporter(
        client: RaiderioClient::fromConfig(),
        guildKey: 'Regenesis-Silvermoon',
        requestDelayMs: 0,
    ))->pull();

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), 'realm=pozzo-delleternita')
        && str_contains($req->url(), 'name=Argus')
    );
});

it('command runs end-to-end with no active members', function () {
    Http::fake();

    $this->artisan('raiderio:pull')
        ->expectsOutputToContain('0 members queried')
        ->assertExitCode(0);
});

it('backfills members.realm from the RIO response', function () {
    $member = makeMember('Sheday-Silvermoon');

    Http::fake([
        '*' => Http::response(rioProfile([
            'name' => 'Sheday',
            'realm' => 'Silvermoon',
        ]), 200),
    ]);

    (new RaiderioSnapshotImporter(
        client: RaiderioClient::fromConfig(),
        guildKey: 'Regenesis-Silvermoon',
        requestDelayMs: 0,
    ))->pull();

    expect($member->fresh()->realm)->toBe('Silvermoon');
});

it('prefers members.realm over the slug map when calling RIO', function () {
    // Member's collapsed realm in the GRM key is "PozzodellEternita" -
    // would normally hit the slug map. With realm backfilled, use the
    // canonical form directly.
    makeMember('Argus-PozzodellEternita', ['realm' => 'Pozzo dell\'Eternita']);

    Http::fake([
        '*' => Http::response(rioProfile(), 200),
    ]);

    (new RaiderioSnapshotImporter(
        client: RaiderioClient::fromConfig(),
        guildKey: 'Regenesis-Silvermoon',
        requestDelayMs: 0,
    ))->pull();

    Http::assertSent(fn ($req) => str_contains($req->url(), 'realm=pozzo-delleternita')
        && str_contains($req->url(), 'name=Argus'));
});

it('slugifyCanonical handles spaces, apostrophes, accents-friendly inputs', function () {
    expect(RealmSlug::slugifyCanonical('Stormrage'))->toBe('stormrage');
    expect(RealmSlug::slugifyCanonical('Twisting Nether'))->toBe('twisting-nether');
    expect(RealmSlug::slugifyCanonical("Pozzo dell'Eternita"))->toBe('pozzo-delleternita');
    expect(RealmSlug::slugifyCanonical("The Sha'tar"))->toBe('the-shatar');
    expect(RealmSlug::slugifyCanonical(null))->toBeNull();
    expect(RealmSlug::slugifyCanonical(''))->toBeNull();
});
