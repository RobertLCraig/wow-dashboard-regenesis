<?php

use App\Models\Member;
use App\Models\MemberMplusRun;
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
        // Default to "logged in today" so the recency gate on RIO ilvl
        // doesn't drop fixture rows. Tests covering parked alts override.
        'last_online_at' => now(),
    ], $overrides));
}

function rioProfile(array $overrides = []): array
{
    return array_replace_recursive([
        'name' => 'Sheday',
        'realm' => 'Silvermoon',
        // Default gear sample is dated to "now" so the freshness gate
        // doesn't drop fixture rows. Tests covering stale RIO data
        // override `created_at` explicitly.
        'gear' => [
            'item_level_equipped' => 282.4,
            'item_level_total' => 285.0,
            'created_at' => now()->toIso8601ZuluString(),
        ],
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
            'gear' => ['item_level_equipped' => 282.7],
        ]), 200),
        'raider.io.test/api/v1/characters/profile?*name=Tute*' => Http::response(rioProfile([
            'name' => 'Tute', 'realm' => 'Twisting Nether',
            'gear' => ['item_level_equipped' => 278.2],
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
    expect($snaps['Sheday-Silvermoon']->ilvl)->toBe(283);  // 282.7 rounds to 283
    expect($snaps['Sheday-Silvermoon']->mplus_score)->toBe(1234.5);
    expect($snaps['Sheday-Silvermoon']->mplus_keystone)->toBe(14);
    expect($snaps['Sheday-Silvermoon']->raid_progression_json['manaforge-omega']['summary'])
        ->toBe('8/8 H');
    expect($snaps['Sheday-Silvermoon']->raid_progression_json['manaforge-omega']['heroic_bosses_killed'])
        ->toBe(8);
    expect($snaps['Tute-TwistingNether']->ilvl)->toBe(278);
    expect($snaps['Tute-TwistingNether']->mplus_score)->toBe(980.0);
});

it('drops ilvl for parked alts whose last login is outside the recency window', function () {
    config(['raiderio.stale_ilvl_window_days' => 90]);
    makeMember('Parked-Silvermoon', ['last_online_at' => now()->subDays(120)]);

    Http::fake([
        '*' => Http::response(rioProfile([
            'gear' => ['item_level_equipped' => 739],
        ]), 200),
    ]);

    (new RaiderioSnapshotImporter(
        client: RaiderioClient::fromConfig(),
        guildKey: 'Regenesis-Silvermoon',
        requestDelayMs: 0,
    ))->pull();

    expect(MemberSnapshot::query()->first()->ilvl)->toBeNull();
});

it('drops ilvl when RIO gear created_at is outside the window even if the char is active', function () {
    // Recently-active char but RIO is still serving a months-old gear
    // sample. Without the RIO-side check we'd surface the stale ilvl.
    config(['raiderio.stale_ilvl_window_days' => 90]);
    makeMember('Refresh-Silvermoon', ['last_online_at' => now()->subDays(10)]);

    Http::fake([
        '*' => Http::response(rioProfile([
            'gear' => [
                'item_level_equipped' => 497,
                'created_at' => now()->subDays(200)->toIso8601ZuluString(),
            ],
        ]), 200),
    ]);

    (new RaiderioSnapshotImporter(
        client: RaiderioClient::fromConfig(),
        guildKey: 'Regenesis-Silvermoon',
        requestDelayMs: 0,
    ))->pull();

    expect(MemberSnapshot::query()->first()->ilvl)->toBeNull();
});

it('keeps ilvl when both the char and the RIO gear sample are within the window', function () {
    config(['raiderio.stale_ilvl_window_days' => 90]);
    makeMember('Active-Silvermoon', ['last_online_at' => now()->subDays(30)]);

    Http::fake([
        '*' => Http::response(rioProfile([
            'gear' => [
                'item_level_equipped' => 282,
                'created_at' => now()->subDays(20)->toIso8601ZuluString(),
            ],
        ]), 200),
    ]);

    (new RaiderioSnapshotImporter(
        client: RaiderioClient::fromConfig(),
        guildKey: 'Regenesis-Silvermoon',
        requestDelayMs: 0,
    ))->pull();

    expect(MemberSnapshot::query()->first()->ilvl)->toBe(282);
});

it('drops ilvl when last_online_at is unknown', function () {
    // last_online_at is null for chars GRM has never seen online.
    // Defensive: don't trust RIO when we can't corroborate recency.
    config(['raiderio.stale_ilvl_window_days' => 90]);
    makeMember('Unknown-Silvermoon', ['last_online_at' => null]);

    Http::fake([
        '*' => Http::response(rioProfile([
            'gear' => ['item_level_equipped' => 282],
        ]), 200),
    ]);

    (new RaiderioSnapshotImporter(
        client: RaiderioClient::fromConfig(),
        guildKey: 'Regenesis-Silvermoon',
        requestDelayMs: 0,
    ))->pull();

    expect(MemberSnapshot::query()->first()->ilvl)->toBeNull();
});

it('skips the recency gate entirely when window is set to 0', function () {
    config(['raiderio.stale_ilvl_window_days' => 0]);
    makeMember('Ancient-Silvermoon', ['last_online_at' => now()->subYears(2)]);

    Http::fake([
        '*' => Http::response(rioProfile([
            'gear' => [
                'item_level_equipped' => 282,
                'created_at' => now()->subYears(2)->toIso8601ZuluString(),
            ],
        ]), 200),
    ]);

    (new RaiderioSnapshotImporter(
        client: RaiderioClient::fromConfig(),
        guildKey: 'Regenesis-Silvermoon',
        requestDelayMs: 0,
    ))->pull();

    expect(MemberSnapshot::query()->first()->ilvl)->toBe(282);
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

it('upserts individual runs from each RIO field, dedupes by completed_at', function () {
    config([
        'raiderio.profile_fields' => [
            'gear', 'raid_progression',
            'mythic_plus_scores_by_season:current',
            'mythic_plus_weekly_highest_level_runs',
            'mythic_plus_previous_weekly_highest_level_runs',
            'mythic_plus_recent_runs',
            'mythic_plus_best_runs',
            'mythic_plus_alternate_runs',
        ],
    ]);
    makeMember('Sheday-Silvermoon');

    // Same physical run shows up in best_runs (with score) and recent_runs
    // (without score). After dedupe it should be one row, with the score
    // preserved.
    $sharedRun = [
        'dungeon' => 'Halls of Atonement',
        'short_name' => 'HoA',
        'mythic_level' => 14,
        'completed_at' => '2026-04-27T18:00:00.000Z',
        'clear_time_ms' => 1500000,
        'par_time_ms' => 1800000,
        'num_keystone_upgrades' => 2,
        'map_challenge_mode_id' => 391,
    ];

    Http::fake([
        '*' => Http::response(rioProfile([
            'mythic_plus_best_runs' => [
                $sharedRun + ['score' => 250.5],
            ],
            'mythic_plus_recent_runs' => [
                $sharedRun, // no score field on this copy
                [
                    'dungeon' => 'Theatre of Pain',
                    'short_name' => 'TOP',
                    'mythic_level' => 10,
                    'completed_at' => '2026-04-26T20:00:00.000Z',
                    'num_keystone_upgrades' => 1,
                    'map_challenge_mode_id' => 381,
                    'clear_time_ms' => 1700000,
                    'par_time_ms' => 1800000,
                ],
                [
                    // Untimed run - num_keystone_upgrades = 0
                    'dungeon' => 'Atal\'Dazar',
                    'short_name' => 'AD',
                    'mythic_level' => 8,
                    'completed_at' => '2026-04-25T19:00:00.000Z',
                    'num_keystone_upgrades' => 0,
                    'map_challenge_mode_id' => 244,
                    'clear_time_ms' => 2400000,
                    'par_time_ms' => 1800000,
                ],
            ],
        ]), 200),
    ]);

    (new RaiderioSnapshotImporter(
        client: RaiderioClient::fromConfig(),
        guildKey: 'Regenesis-Silvermoon',
        requestDelayMs: 0,
    ))->pull();

    // Three distinct completed_at values across the response = three rows.
    expect(MemberMplusRun::query()->count())->toBe(3);

    $halls = MemberMplusRun::query()->where('dungeon_short_name', 'HoA')->first();
    expect($halls)->not->toBeNull();
    expect($halls->mythic_level)->toBe(14);
    expect($halls->num_keystone_upgrades)->toBe(2);
    expect($halls->source)->toBe(MemberMplusRun::SOURCE_SEASON_BEST); // higher-priority source wins
    expect($halls->score)->toBe(250.5);                                // score from best_runs preserved
    expect($halls->season_slug)->toBe('season-tww-3');                 // tagged from current season

    $atal = MemberMplusRun::query()->where('dungeon_short_name', 'AD')->first();
    expect($atal->isTimed())->toBeFalse();                             // untimed completion
    expect($atal->num_keystone_upgrades)->toBe(0);
});

it('re-upserting the same run only bumps last_seen_at', function () {
    config([
        'raiderio.profile_fields' => [
            'gear', 'mythic_plus_scores_by_season:current',
            'mythic_plus_recent_runs',
        ],
    ]);
    makeMember('Sheday-Silvermoon');

    Http::fake([
        '*' => Http::response(rioProfile([
            'mythic_plus_recent_runs' => [[
                'dungeon' => 'Halls of Atonement',
                'short_name' => 'HoA',
                'mythic_level' => 14,
                'completed_at' => '2026-04-27T18:00:00.000Z',
                'num_keystone_upgrades' => 2,
                'map_challenge_mode_id' => 391,
            ]],
        ]), 200),
    ]);

    $importer = new RaiderioSnapshotImporter(
        client: RaiderioClient::fromConfig(),
        guildKey: 'Regenesis-Silvermoon',
        requestDelayMs: 0,
    );
    $importer->pull();

    $first = MemberMplusRun::query()->first();
    $firstSeenAt = $first->first_seen_at;

    // Second pull a second later - the run is already in the DB.
    sleep(1);
    $importer->pull();

    expect(MemberMplusRun::query()->count())->toBe(1);
    $second = MemberMplusRun::query()->first();
    expect($second->first_seen_at->equalTo($firstSeenAt))->toBeTrue();
    expect($second->last_seen_at->greaterThan($firstSeenAt))->toBeTrue();
});

it('skips RIO run rows that lack a parseable completed_at', function () {
    // Pre-existing test fixtures ship runs with only mythic_level + dungeon.
    // Those should not produce member_mplus_runs rows - they're missing the
    // dedupe key and would corrupt the per-day timeline if persisted.
    config([
        'raiderio.profile_fields' => [
            'gear', 'mythic_plus_scores_by_season:current',
            'mythic_plus_weekly_highest_level_runs',
        ],
    ]);
    makeMember('Sheday-Silvermoon');

    Http::fake([
        '*' => Http::response(rioProfile([
            'mythic_plus_weekly_highest_level_runs' => [
                ['mythic_level' => 12, 'dungeon' => 'Theatre of Pain'],
                ['mythic_level' => 14, 'dungeon' => 'Halls of Atonement'],
            ],
        ]), 200),
    ]);

    (new RaiderioSnapshotImporter(
        client: RaiderioClient::fromConfig(),
        guildKey: 'Regenesis-Silvermoon',
        requestDelayMs: 0,
    ))->pull();

    expect(MemberMplusRun::query()->count())->toBe(0);
});
