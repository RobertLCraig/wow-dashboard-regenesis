<?php

use App\Models\Member;
use App\Models\MemberTeam;
use App\Models\TeamMapping;
use App\Models\User;
use App\Services\Discord\RoleVerifier;
use App\Services\Grm\GrmNormalizer;
use App\Services\Teams\TeamResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Pull a member's team list from the pivot. Helper so each test can
 * read what GrmNormalizer / TeamResolver wrote without re-loading the
 * model; sorted so order is stable for the equality assertions.
 *
 * @return list<string>
 */
function teamsFor(string $name): array
{
    $member = Member::query()->where('name', $name)->first();
    if (! $member) return [];
    $teams = MemberTeam::query()->where('member_id', $member->id)->pluck('team')->all();
    sort($teams);
    return $teams;
}

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
        'discord.role_cache_ttl_minutes' => 5,
    ]);
    Cache::flush();
});

function seedMappings(): void
{
    foreach ([
        ['source' => 'grm_rank', 'key' => 'Mythic Raider', 'team' => 'mythic', 'priority' => 100],
        ['source' => 'grm_rank', 'key' => 'Heroic Raider', 'team' => 'heroic', 'priority' => 100],
        ['source' => 'grm_rank', 'key' => 'Heroic Try out', 'team' => 'heroic_trial', 'priority' => 100],
        ['source' => 'grm_rank', 'key' => 'Officer', 'team' => null, 'priority' => 100],
        ['source' => 'discord_role', 'key' => '1423356186865958923', 'label' => 'Mythic Raider', 'team' => 'mythic', 'priority' => 300],
        ['source' => 'discord_role', 'key' => '1247628717832802409', 'label' => 'Trial Raider', 'team' => 'mythic_trial', 'priority' => 200],
        ['source' => 'discord_role', 'key' => '1247286726809096265', 'label' => 'Heroic Raider', 'team' => 'heroic', 'priority' => 100],
    ] as $row) {
        TeamMapping::query()->create($row);
    }
    app(TeamResolver::class)->flush();
}

it('resolves a rank to its team', function () {
    seedMappings();
    $r = app(TeamResolver::class);

    expect($r->forRank('Mythic Raider'))->toBe('mythic');
    expect($r->forRank('Heroic Raider'))->toBe('heroic');
    expect($r->forRank('Heroic Try out'))->toBe('heroic_trial');
    expect($r->forRank('Officer'))->toBeNull();           // mapped to null on purpose
    expect($r->forRank('Some Random Rank'))->toBeNull();  // not mapped at all
    expect($r->forRank(null))->toBeNull();
});

it('picks the highest-priority Discord role when a user holds several', function () {
    seedMappings();
    $r = app(TeamResolver::class);

    // Officer with both Mythic Raider (300) and Heroic Raider (100) -> mythic wins
    expect($r->forRoleIds(['1423356186865958923', '1247286726809096265']))->toBe('mythic');
    // Heroic raider only
    expect($r->forRoleIds(['1247286726809096265']))->toBe('heroic');
    // Trial only
    expect($r->forRoleIds(['1247628717832802409']))->toBe('mythic_trial');
    // No mapped roles
    expect($r->forRoleIds(['9999999999999999']))->toBeNull();
    expect($r->forRoleIds([]))->toBeNull();
});

it('GrmNormalizer sets members.team from rank_name on upsert', function () {
    seedMappings();

    // Build a minimal payload matching GRM's shape with two characters
    // on different ranks.
    $payload = [
        'GRM_GuildMemberHistory_Save' => [
            'Regenesis-Silvermoon' => [
                'Heroguy-Silvermoon' => [
                    'GUID' => 'Player-3391-AAA',
                    'class' => 'PRIEST', 'race' => 'Human', 'level' => 80,
                    'rankName' => 'Heroic Raider', 'rankIndex' => 4,
                    'isOnline' => false, 'isMobile' => false,
                ],
                'Mythbro-Silvermoon' => [
                    'GUID' => 'Player-3391-BBB',
                    'class' => 'WARRIOR', 'race' => 'Orc', 'level' => 80,
                    'rankName' => 'Mythic Raider', 'rankIndex' => 3,
                    'isOnline' => false, 'isMobile' => false,
                ],
                'Officerdude-Silvermoon' => [
                    'GUID' => 'Player-3391-CCC',
                    'class' => 'PALADIN', 'race' => 'Human', 'level' => 80,
                    'rankName' => 'Officer', 'rankIndex' => 1,
                    'isOnline' => false, 'isMobile' => false,
                ],
            ],
        ],
    ];

    $snapshot = \App\Models\Snapshot::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'captured_at' => now(),
        'source' => \App\Models\Snapshot::SOURCE_GRM,
        'payload_hash' => hash('sha256', 'x'),
    ]);

    (new GrmNormalizer('Regenesis-Silvermoon'))->apply($snapshot, $payload);

    expect(teamsFor('Heroguy-Silvermoon'))->toBe(['heroic']);
    expect(teamsFor('Mythbro-Silvermoon'))->toBe(['mythic']);
    // Officer rank deliberately maps to null - we don't infer team from it.
    expect(teamsFor('Officerdude-Silvermoon'))->toBe([]);
});

it('recomputeMembers updates team for members already in the DB', function () {
    seedMappings();

    $heroguy = Member::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'name' => 'Heroguy-Silvermoon',
        'rank_name' => 'Heroic Raider',
        // No team rows yet; pretend the mapping was added after this
        // member was ingested.
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    $stale = Member::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'name' => 'Stale-Silvermoon',
        'rank_name' => 'Heroic Raider',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);
    // Stale rank-derived row that should be corrected to heroic.
    MemberTeam::query()->create([
        'member_id' => $stale->id,
        'team' => 'mythic',
        'is_override' => false,
    ]);

    $updated = app(TeamResolver::class)->recomputeMembers('Regenesis-Silvermoon');

    expect($updated)->toBe(2);
    expect(teamsFor('Heroguy-Silvermoon'))->toBe(['heroic']);
    expect(teamsFor('Stale-Silvermoon'))->toBe(['heroic']);
});

it('recomputeMembers leaves overridden members alone', function () {
    seedMappings();

    $member = Member::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'name' => 'Officerinheroic-Silvermoon',
        'rank_name' => 'Officer', // rank says null
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    // Officer set this player to heroic because they actually raid there.
    app(TeamResolver::class)->setOverrides($member, ['heroic'], userId: null);

    $updated = app(TeamResolver::class)->recomputeMembers('Regenesis-Silvermoon');

    // The override should not have been disturbed.
    expect(teamsFor('Officerinheroic-Silvermoon'))->toBe(['heroic']);

    // Updated count is whatever rank-recompute changed elsewhere; the
    // overridden member must be excluded.
    $row = MemberTeam::query()->where('member_id', $member->id)->first();
    expect($row?->is_override)->toBeTrue();
});

it('a member can be overridden onto multiple teams', function () {
    seedMappings();

    $member = Member::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'name' => 'Crossteam-Silvermoon',
        'rank_name' => 'Heroic Raider',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    app(TeamResolver::class)->setOverrides($member, ['heroic', 'mythic'], userId: null);

    expect(teamsFor('Crossteam-Silvermoon'))->toBe(['heroic', 'mythic']);
    expect($member->fresh()->load('teams')->teamValues())->toBe(['mythic', 'heroic']);
});

it('clearOverrides reverts to rank-derived rows', function () {
    seedMappings();

    $member = Member::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'name' => 'Tobereverted-Silvermoon',
        'rank_name' => 'Heroic Raider',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    $resolver = app(TeamResolver::class);
    $resolver->setOverrides($member, ['mythic'], userId: null);
    expect(teamsFor('Tobereverted-Silvermoon'))->toBe(['mythic']);

    $resolver->clearOverrides($member);
    expect(teamsFor('Tobereverted-Silvermoon'))->toBe(['heroic']);

    $row = MemberTeam::query()->where('member_id', $member->id)->first();
    expect($row?->is_override)->toBeFalse();
});

it('character team override route saves an override and shows the badge', function () {
    seedMappings();

    $member = Member::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'name' => 'Webuser-Silvermoon',
        'rank_name' => 'Officer',
        'class' => 'PALADIN',
        'level' => 80,
        'rank_index' => 1,
        'status' => Member::STATUS_ACTIVE,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);
    $user = User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);

    $this->actingAs($user)
        ->post('/character/Webuser-Silvermoon/teams', [
            'teams' => ['heroic', 'mythic'],
            'action' => 'save',
        ])
        ->assertRedirect('/character/Webuser-Silvermoon');

    expect(teamsFor('Webuser-Silvermoon'))->toBe(['heroic', 'mythic']);

    // The character page renders both teams + the override badge.
    $resp = $this->actingAs($user)->get('/character/Webuser-Silvermoon');
    $resp->assertOk()
        ->assertSee('Heroic')
        ->assertSee('Mythic')
        ->assertSee('override');
});

it('clearing the override via the form reverts to rank-derived', function () {
    seedMappings();

    $member = Member::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'name' => 'Reverter-Silvermoon',
        'rank_name' => 'Heroic Raider',
        'class' => 'PALADIN',
        'level' => 80,
        'rank_index' => 4,
        'status' => Member::STATUS_ACTIVE,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);
    app(TeamResolver::class)->setOverrides($member, ['mythic'], userId: null);

    $user = User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);

    $this->actingAs($user)
        ->post('/character/Reverter-Silvermoon/teams', ['action' => 'clear'])
        ->assertRedirect('/character/Reverter-Silvermoon');

    expect(teamsFor('Reverter-Silvermoon'))->toBe(['heroic']);
});

it('non-officer cannot set or clear team overrides', function () {
    $member = Member::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'name' => 'Lockdown-Silvermoon',
        'rank_name' => 'Member',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);
    $user = User::factory()->create(['tier' => null, 'last_role_check_at' => now()]);

    $this->actingAs($user)
        ->post('/character/Lockdown-Silvermoon/teams', ['teams' => ['mythic']])
        ->assertStatus(403);
});

it('RoleVerifier sets users.team from the configured Discord role mapping', function () {
    seedMappings();

    $user = User::factory()->create([
        'discord_id' => '900000000000000000',
        'discord_refresh_token' => Crypt::encryptString('rt-token'),
    ]);

    Http::fake([
        'discord.com/api/v10/oauth2/token' => Http::response(['access_token' => 'at-token'], 200),
        'discord.com/api/v10/users/@me/guilds/*/member' => Http::response([
            'roles' => ['1247286726809096265', '99999999999999999'], // Heroic Raider + a noise role
        ], 200),
    ]);

    RoleVerifier::fromConfig()->tierFor($user, force: true);

    expect($user->fresh()->team)->toBe('heroic');
});

it('admin UI 403s a non-officer', function () {
    $user = User::factory()->create(['tier' => null, 'last_role_check_at' => now()]);
    $this->actingAs($user)->get('/admin/teams')->assertStatus(403);
});

it('admin UI lists ranks observed in the roster + persisted mappings', function () {
    seedMappings();

    Member::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'name' => 'X-Silvermoon',
        'rank_name' => 'Heroic Raider',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    $user = User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);

    $this->actingAs($user)
        ->get('/admin/teams')
        ->assertOk()
        ->assertSee('Heroic Raider')   // observed rank
        ->assertSee('1423356186865958923'); // mapped role from seed
});

it('admin UI saves new role + recomputes members.team', function () {
    seedMappings();

    Member::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'name' => 'Newrank-Silvermoon',
        'rank_name' => 'Casual',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    $user = User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);

    $this->actingAs($user)
        ->post('/admin/teams', [
            'ranks' => [['key' => 'Casual', 'team' => 'heroic_trial']],
            'roles' => [],
            'new_role' => [
                'key' => '987654321098765432',
                'label' => 'New role',
                'team' => 'heroic',
                'priority' => 50,
            ],
        ])
        ->assertRedirect('/admin/teams');

    expect(TeamMapping::query()
        ->where('source', 'discord_role')
        ->where('key', '987654321098765432')
        ->value('team'))->toBe('heroic');
    expect(teamsFor('Newrank-Silvermoon'))->toBe(['heroic_trial']);
});
