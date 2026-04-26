<?php

use App\Models\Member;
use App\Models\MemberSnapshot;
use App\Models\Snapshot;
use App\Models\TeamMapping;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'grm.guild_key' => 'Regenesis-Silvermoon',
        'discord.guild_id' => '1247256415542841416',
        'discord.roles' => [
            'gm' => '1247279261434384415',
            'big6' => '1490762074584780951',
            'officer' => '1247278529163296789',
        ],
    ]);
});

function makeTeamMember(string $name, ?string $team, array $overrides = []): Member
{
    return Member::query()->create(array_replace([
        'guild_key' => 'Regenesis-Silvermoon',
        'name' => $name,
        'class' => 'PRIEST',
        'level' => 80,
        'rank_index' => 5,
        'team' => $team,
        'status' => Member::STATUS_ACTIVE,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ], $overrides));
}

function snapshotWithRow(int $memberId, array $rowOverrides = []): Snapshot
{
    $snapshot = Snapshot::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'captured_at' => now(),
        'source' => Snapshot::SOURCE_RAIDERIO,
        'payload_hash' => hash('sha256', (string) $memberId . microtime()),
    ]);
    MemberSnapshot::query()->create(array_replace([
        'snapshot_id' => $snapshot->id,
        'member_id' => $memberId,
        'ilvl' => 640,
        'mplus_score' => 1000.0,
        'mplus_keystone' => 12,
        'raid_progression_json' => [
            'manaforge-omega' => [
                'summary' => '8/8 H 3/8 M',
                'total_bosses' => 8,
                'normal_bosses_killed' => 8,
                'heroic_bosses_killed' => 8,
                'mythic_bosses_killed' => 3,
            ],
        ],
    ], $rowOverrides));
    return $snapshot;
}

it('dashboard shows the team progression widget with per-team rollups', function () {
    $h1 = makeTeamMember('Healer-Silvermoon', TeamMapping::TEAM_HEROIC);
    $h2 = makeTeamMember('Tank-Silvermoon', TeamMapping::TEAM_HEROIC);
    $m1 = makeTeamMember('Bruiser-Silvermoon', TeamMapping::TEAM_MYTHIC);

    // Mythic raider has farther progression + higher ilvl than heroic.
    $snapshot = snapshotWithRow($h1->id, ['ilvl' => 640, 'mplus_score' => 1100, 'mplus_keystone' => 12]);
    MemberSnapshot::query()->create([
        'snapshot_id' => $snapshot->id,
        'member_id' => $h2->id,
        'ilvl' => 642,
        'mplus_score' => 950.0,
        'mplus_keystone' => 10,
        'raid_progression_json' => [
            'manaforge-omega' => [
                'summary' => '8/8 H',
                'total_bosses' => 8,
                'heroic_bosses_killed' => 8,
                'mythic_bosses_killed' => 0,
            ],
        ],
    ]);
    MemberSnapshot::query()->create([
        'snapshot_id' => $snapshot->id,
        'member_id' => $m1->id,
        'ilvl' => 660,
        'mplus_score' => 2400.0,
        'mplus_keystone' => 18,
        'raid_progression_json' => [
            'manaforge-omega' => [
                'summary' => '8/8 H 6/8 M',
                'total_bosses' => 8,
                'heroic_bosses_killed' => 8,
                'mythic_bosses_killed' => 6,
            ],
        ],
    ]);

    $user = User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);

    $resp = $this->actingAs($user)->get('/dashboard');
    $resp->assertOk();
    $resp->assertSee('Team progression');
    $resp->assertSee('Heroic');
    $resp->assertSee('Mythic');
    // Best heroic raid progression from h1 row (3 mythic kills) wins
    // over h2 (0 mythic). Mythic team's 6 mythic kills wins overall
    // for that panel.
    $resp->assertSee('8/8 H 3/8 M');
    $resp->assertSee('8/8 H 6/8 M');
});

it('widget renders empty state when no member has a team assigned', function () {
    makeTeamMember('Untagged-Silvermoon', null, ['team' => null]);

    $user = User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);

    $resp = $this->actingAs($user)->get('/dashboard');
    $resp->assertOk();
    $resp->assertSee('No members have a team assigned');
});

it('officer can trigger an on-demand RIO sync', function () {
    makeTeamMember('Sheday-Silvermoon', TeamMapping::TEAM_HEROIC);

    config([
        'raiderio.base_url' => 'https://raider.io.test/api/v1',
        'raiderio.region' => 'eu',
        'raiderio.profile_fields' => ['gear', 'raid_progression', 'mythic_plus_scores_by_season:current', 'mythic_plus_weekly_highest_level_runs'],
        'raiderio.request_delay_ms' => 0,
        'raiderio.timeout' => 5,
        'raiderio.realm_slugs' => [],
    ]);

    Http::fake([
        '*raider.io.test*' => Http::response([
            'name' => 'Sheday', 'realm' => 'Silvermoon',
            'gear' => ['item_level_equipped' => 642.0],
            'raid_progression' => [],
            'mythic_plus_scores_by_season' => [['scores' => ['all' => 800.0]]],
            'mythic_plus_weekly_highest_level_runs' => [],
        ], 200),
    ]);

    $user = User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);

    $this->actingAs($user)
        ->post('/admin/raiderio/sync')
        ->assertRedirect('/admin/teams')
        ->assertSessionHas('status');

    expect(Snapshot::query()->where('source', Snapshot::SOURCE_RAIDERIO)->count())->toBe(1);
});

it('officer-trigger is rate-limited to once per hour', function () {
    $user = User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);

    config([
        'raiderio.base_url' => 'https://raider.io.test/api/v1',
        'raiderio.request_delay_ms' => 0,
    ]);
    Http::fake(['*' => Http::response(['name' => 'x', 'realm' => 'Silvermoon'], 200)]);

    $this->actingAs($user)->post('/admin/raiderio/sync')->assertRedirect('/admin/teams');
    $this->actingAs($user)
        ->post('/admin/raiderio/sync')
        ->assertRedirect('/admin/teams')
        ->assertSessionHasErrors('raiderio');
});

it('non-officer is 403d from the RIO sync route', function () {
    $user = User::factory()->create(['tier' => null, 'last_role_check_at' => now()]);
    $this->actingAs($user)->post('/admin/raiderio/sync')->assertStatus(403);
});

it('short-circuits when a fresh snapshot already exists (within last minute)', function () {
    Snapshot::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'captured_at' => now()->subSeconds(20),
        'source' => Snapshot::SOURCE_RAIDERIO,
        'payload_hash' => 'stale-hash',
        'member_count' => 12,
    ]);
    Http::fake();
    $user = User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);

    $this->actingAs($user)
        ->post('/admin/raiderio/sync')
        ->assertRedirect('/admin/teams')
        ->assertSessionHas('status', fn ($status) => str_contains($status, 'already fresh'));

    // No HTTP calls were made because we returned the cached summary.
    Http::assertNothingSent();
    // No new snapshot row either.
    expect(Snapshot::query()->where('source', Snapshot::SOURCE_RAIDERIO)->count())->toBe(1);
});

it('runs the importer when the most recent snapshot is older than the freshness window', function () {
    Snapshot::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'captured_at' => now()->subMinutes(5),
        'source' => Snapshot::SOURCE_RAIDERIO,
        'payload_hash' => 'old-hash',
        'member_count' => 12,
    ]);
    makeTeamMember('Sheday-Silvermoon', TeamMapping::TEAM_HEROIC);

    config([
        'raiderio.base_url' => 'https://raider.io.test/api/v1',
        'raiderio.request_delay_ms' => 0,
    ]);
    Http::fake([
        '*' => Http::response([
            'name' => 'Sheday', 'realm' => 'Silvermoon',
            'gear' => ['item_level_equipped' => 642.0],
            'raid_progression' => [],
            'mythic_plus_scores_by_season' => [['scores' => ['all' => 800.0]]],
            'mythic_plus_weekly_highest_level_runs' => [],
        ], 200),
    ]);

    $user = User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);

    $this->actingAs($user)
        ->post('/admin/raiderio/sync')
        ->assertRedirect('/admin/teams')
        ->assertSessionHas('status', fn ($s) => str_contains($s, 'sync done'));

    Http::assertSentCount(1);
    expect(Snapshot::query()->where('source', Snapshot::SOURCE_RAIDERIO)->count())->toBe(2);
});

it('rejects a concurrent press while another sync is running', function () {
    makeTeamMember('Sheday-Silvermoon', TeamMapping::TEAM_HEROIC);
    Http::fake();

    // Pretend another worker is already inside the importer by holding
    // the lock manually before the request runs.
    $lock = Cache::lock('raiderio-sync:running', 60);
    expect($lock->get())->toBeTrue();

    $user = User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);

    $this->actingAs($user)
        ->post('/admin/raiderio/sync')
        ->assertRedirect('/admin/teams')
        ->assertSessionHas('status', fn ($s) => str_contains($s, 'already running'));

    // The blocked caller didn't burn an HTTP call OR a rate-limit token.
    Http::assertNothingSent();
    expect(RateLimiter::attempts('raiderio-sync:user:' . $user->id))->toBe(0);

    $lock->release();
});

