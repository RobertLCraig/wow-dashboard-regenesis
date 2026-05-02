<?php

use App\Models\Member;
use App\Models\MemberRaidSnapshot;
use App\Models\MemberSnapshot;
use App\Models\Snapshot;
use App\Models\TeamMapping;
use App\Models\User;
use App\Services\Sync\SyncStatus;
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
    // Allow tests to override the team via $overrides['team'] = null/value.
    $effectiveTeam = array_key_exists('team', $overrides) ? $overrides['team'] : $team;
    unset($overrides['team']);

    $member = Member::query()->create(array_replace([
        'guild_key' => 'Regenesis-Silvermoon',
        'name' => $name,
        'class' => 'PRIEST',
        'level' => 80,
        'rank_index' => 5,
        'status' => Member::STATUS_ACTIVE,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ], $overrides));

    if ($effectiveTeam !== null) {
        \App\Models\MemberTeam::query()->create([
            'member_id' => $member->id,
            'team' => $effectiveTeam,
            'is_override' => false,
        ]);
    }

    return $member;
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
    // h1 has heroic + mythic Blizzard kills (crossover raider).
    // h2 has heroic kills only.
    // m1 is on the Mythic team with 6/8 mythic kills.
    // The heroic team cap means its matrix row shows H8/8, not M3/8.
    $h1 = makeTeamMember('Healer-Silvermoon',  TeamMapping::TEAM_HEROIC);
    $h2 = makeTeamMember('Tank-Silvermoon',    TeamMapping::TEAM_HEROIC);
    $m1 = makeTeamMember('Bruiser-Silvermoon', TeamMapping::TEAM_MYTHIC);

    $rio = Snapshot::query()->create([
        'guild_key' => 'Regenesis-Silvermoon', 'captured_at' => now(),
        'source' => Snapshot::SOURCE_RAIDERIO, 'payload_hash' => 'rollup-rio',
    ]);
    foreach ([$h1->id, $h2->id] as $mid) {
        MemberSnapshot::query()->create(['snapshot_id' => $rio->id, 'member_id' => $mid, 'ilvl' => 640]);
    }
    MemberSnapshot::query()->create(['snapshot_id' => $rio->id, 'member_id' => $m1->id, 'ilvl' => 660]);

    $raidSnap = Snapshot::query()->create([
        'guild_key' => 'Regenesis-Silvermoon', 'captured_at' => now(),
        'source' => Snapshot::SOURCE_BLIZZARD_RAIDS, 'payload_hash' => 'rollup-bliz',
    ]);
    $enc  = fn (int $id, bool $k): array => [
        'encounter' => ['id' => $id, 'name' => "Boss{$id}"],
        'completed_count' => $k ? 1 : 0, 'last_kill_timestamp' => $k ? 1_700_000_000 : 0,
    ];
    $wrap = fn (array $modes): array => [[
        'expansion' => ['id' => 503, 'name' => 'TWW'],
        'instances' => [['instance' => ['id' => 1296, 'name' => 'Manaforge Omega'], 'modes' => $modes]],
    ]];
    $hMode = fn (array $encs): array => [
        'difficulty' => ['type' => 'HEROIC', 'name' => 'Heroic'],
        'progress' => ['completed_count' => count(array_filter($encs, fn ($e) => $e['completed_count'])), 'total_count' => count($encs), 'encounters' => $encs],
    ];
    $mMode = fn (array $encs): array => [
        'difficulty' => ['type' => 'MYTHIC', 'name' => 'Mythic'],
        'progress' => ['completed_count' => count(array_filter($encs, fn ($e) => $e['completed_count'])), 'total_count' => count($encs), 'encounters' => $encs],
    ];

    $all8  = array_map(fn ($i) => $enc($i, true),    range(1, 8));
    $m3of8 = array_map(fn ($i) => $enc($i, $i <= 3), range(1, 8));
    $m6of8 = array_map(fn ($i) => $enc($i, $i <= 6), range(1, 8));

    MemberRaidSnapshot::query()->create(['snapshot_id' => $raidSnap->id, 'member_id' => $h1->id, 'expansions' => $wrap([$hMode($all8), $mMode($m3of8)])]);
    MemberRaidSnapshot::query()->create(['snapshot_id' => $raidSnap->id, 'member_id' => $h2->id, 'expansions' => $wrap([$hMode($all8)])]);
    MemberRaidSnapshot::query()->create(['snapshot_id' => $raidSnap->id, 'member_id' => $m1->id, 'expansions' => $wrap([$hMode($all8), $mMode($m6of8)])]);

    $user = User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);
    $resp = $this->actingAs($user)->get('/dashboard');

    $resp->assertOk();
    // Heroic team matrix summary: H8/8 only (mythic difficulty capped/excluded).
    $resp->assertSee('H8/8');
    // Mythic team matrix summary: M6/8.
    $resp->assertSee('M6/8');
    // h1's 3/8 mythic kills must NOT appear as M3/8 on the heroic team row.
    $resp->assertDontSee('M3/8');
});

it('caps the Heroic team rollup at heroic difficulty even when one member has mythic kills', function () {
    // Regression test: a Heroic-team member who joined the Mythic raid
    // for a few bosses should NOT pull the team's matrix summary to M4/9.
    $hero      = makeTeamMember('Hero-Silvermoon',      TeamMapping::TEAM_HEROIC);
    $crossover = makeTeamMember('Crossover-Silvermoon', TeamMapping::TEAM_HEROIC);

    $rio = Snapshot::query()->create([
        'guild_key' => 'Regenesis-Silvermoon', 'captured_at' => now(),
        'source' => Snapshot::SOURCE_RAIDERIO, 'payload_hash' => 'cap-rio',
    ]);
    foreach ([$hero->id, $crossover->id] as $mid) {
        MemberSnapshot::query()->create(['snapshot_id' => $rio->id, 'member_id' => $mid, 'ilvl' => 285]);
    }

    // hero:      6/9 heroic only.
    // crossover: 8/9 heroic + 4/9 mythic (the crossover case being tested).
    $raidSnap = Snapshot::query()->create([
        'guild_key' => 'Regenesis-Silvermoon', 'captured_at' => now(),
        'source' => Snapshot::SOURCE_BLIZZARD_RAIDS, 'payload_hash' => 'cap-bliz',
    ]);
    $enc  = fn (int $id, bool $k): array => [
        'encounter' => ['id' => $id, 'name' => "Boss{$id}"],
        'completed_count' => $k ? 1 : 0, 'last_kill_timestamp' => $k ? 1_700_000_000 : 0,
    ];
    $wrap = fn (array $modes): array => [[
        'expansion' => ['id' => 510, 'name' => 'Midnight'],
        'instances' => [['instance' => ['id' => 1400, 'name' => 'Nerub-ar Palace'], 'modes' => $modes]],
    ]];
    $hMode = fn (array $encs): array => [
        'difficulty' => ['type' => 'HEROIC', 'name' => 'Heroic'],
        'progress' => ['completed_count' => count(array_filter($encs, fn ($e) => $e['completed_count'])), 'total_count' => count($encs), 'encounters' => $encs],
    ];
    $mMode = fn (array $encs): array => [
        'difficulty' => ['type' => 'MYTHIC', 'name' => 'Mythic'],
        'progress' => ['completed_count' => count(array_filter($encs, fn ($e) => $e['completed_count'])), 'total_count' => count($encs), 'encounters' => $encs],
    ];

    MemberRaidSnapshot::query()->create(['snapshot_id' => $raidSnap->id, 'member_id' => $hero->id,      'expansions' => $wrap([$hMode(array_map(fn ($i) => $enc($i, $i <= 6), range(1, 9)))])]);
    MemberRaidSnapshot::query()->create(['snapshot_id' => $raidSnap->id, 'member_id' => $crossover->id, 'expansions' => $wrap([$hMode(array_map(fn ($i) => $enc($i, $i <= 8), range(1, 9))), $mMode(array_map(fn ($i) => $enc($i, $i <= 4), range(1, 9)))])]);

    $user = User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);
    $resp = $this->actingAs($user)->get('/dashboard');

    $resp->assertOk();
    // Heroic team: best heroic across the team is H8/9 (crossover).
    $resp->assertSee('H8/9');
    // Crossover's 4/9 mythic kills must NOT appear in the heroic team row.
    $resp->assertDontSee('M4/9');
});

it('renders the boss-by-boss breakdown for each team from blizzard raid snapshots', function () {
    $h1 = makeTeamMember('Healer-Silvermoon', TeamMapping::TEAM_HEROIC);
    $h2 = makeTeamMember('Tank-Silvermoon', TeamMapping::TEAM_HEROIC);

    // RIO snapshot first so the widget has the headline numbers.
    snapshotWithRow($h1->id, ['ilvl' => 640]);

    // Blizzard raid-encounters snapshot. Healer has Plexus + Loom; Tank
    // has Plexus only; Soulbinder is undown for the team.
    $raidSnap = Snapshot::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'captured_at' => now(),
        'source' => Snapshot::SOURCE_BLIZZARD_RAIDS,
        'payload_hash' => 'breakdown-test',
    ]);
    $manaforge = fn (array $encounters) => [
        ['expansion' => ['id' => 503, 'name' => 'TWW'], 'instances' => [
            ['instance' => ['id' => 1296, 'name' => 'Manaforge Omega'], 'modes' => [
                ['difficulty' => ['type' => 'HEROIC', 'name' => 'Heroic'], 'progress' => [
                    'completed_count' => count(array_filter($encounters, fn ($e) => $e['completed_count'] > 0)),
                    'total_count' => 3,
                    'encounters' => $encounters,
                ]],
            ]],
        ]],
    ];
    MemberRaidSnapshot::query()->create([
        'snapshot_id' => $raidSnap->id,
        'member_id' => $h1->id,
        'expansions' => $manaforge([
            ['encounter' => ['id' => 1, 'name' => 'Plexus Sentinel'], 'completed_count' => 1, 'last_kill_timestamp' => 1_700_000_000_000],
            ['encounter' => ['id' => 2, 'name' => "Loom'ithar"], 'completed_count' => 1, 'last_kill_timestamp' => 1_700_000_000_000],
            ['encounter' => ['id' => 3, 'name' => 'Soulbinder'], 'completed_count' => 0, 'last_kill_timestamp' => 0],
        ]),
    ]);
    MemberRaidSnapshot::query()->create([
        'snapshot_id' => $raidSnap->id,
        'member_id' => $h2->id,
        'expansions' => $manaforge([
            ['encounter' => ['id' => 1, 'name' => 'Plexus Sentinel'], 'completed_count' => 1, 'last_kill_timestamp' => 1_700_000_000_000],
            ['encounter' => ['id' => 2, 'name' => "Loom'ithar"], 'completed_count' => 0, 'last_kill_timestamp' => 0],
            ['encounter' => ['id' => 3, 'name' => 'Soulbinder'], 'completed_count' => 0, 'last_kill_timestamp' => 0],
        ]),
    ]);

    $user = User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);

    $resp = $this->actingAs($user)->get('/dashboard');
    $resp->assertOk();
    $resp->assertSee('Manaforge Omega');
    $resp->assertSee('Plexus Sentinel');
    // Apostrophe gets escaped by Blade -> assertSee with default $escape=true
    // re-escapes our expected value so the comparison matches the rendered HTML.
    $resp->assertSee("Loom'ithar");
    $resp->assertSee('Soulbinder');
    // 2 of 3 bosses team-killed on Heroic. The new layout puts the
    // count in a progress-row cell and the difficulty in a separate
    // badge, so assert each piece is on the page.
    $resp->assertSee('2/3');
    $resp->assertSee('Heroic');
});

it('renders the comparison table with one column per team and an insights footer when the data supports it', function () {
    // Mythic team avg ilvl 660 vs Heroic team avg ilvl 642: a +18
    // delta should surface as an insight line. Heroic team also clears
    // both Heroic encounters, which should show up as an AOTC line.
    $m1 = makeTeamMember('M1-Silvermoon', TeamMapping::TEAM_MYTHIC);
    $h1 = makeTeamMember('H1-Silvermoon', TeamMapping::TEAM_HEROIC);
    $h2 = makeTeamMember('H2-Silvermoon', TeamMapping::TEAM_HEROIC);

    $rio = Snapshot::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'captured_at' => now(),
        'source' => Snapshot::SOURCE_RAIDERIO,
        'payload_hash' => 'compare-test',
    ]);
    MemberSnapshot::query()->create([
        'snapshot_id' => $rio->id, 'member_id' => $m1->id,
        'ilvl' => 660, 'mplus_score' => 2400.0, 'mplus_keystone' => 18,
        'raid_progression_json' => ['mirrorhall' => [
            'summary' => '6/8 M', 'total_bosses' => 8,
            'mythic_bosses_killed' => 6, 'heroic_bosses_killed' => 8,
        ]],
    ]);
    foreach ([$h1, $h2] as $h) {
        MemberSnapshot::query()->create([
            'snapshot_id' => $rio->id, 'member_id' => $h->id,
            'ilvl' => 642, 'mplus_score' => 1100.0, 'mplus_keystone' => 12,
            'raid_progression_json' => ['mirrorhall' => [
                'summary' => '8/8 H', 'total_bosses' => 8,
                'heroic_bosses_killed' => 8,
            ]],
        ]);
    }

    $raidSnap = Snapshot::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'captured_at' => now(),
        'source' => Snapshot::SOURCE_BLIZZARD_RAIDS,
        'payload_hash' => 'compare-bliz',
    ]);
    $heroicAllCleared = [
        ['encounter' => ['id' => 1, 'name' => 'Plexus'], 'completed_count' => 1, 'last_kill_timestamp' => 1],
        ['encounter' => ['id' => 2, 'name' => "Loom'ithar"], 'completed_count' => 1, 'last_kill_timestamp' => 1],
    ];
    foreach ([$m1, $h1, $h2] as $member) {
        MemberRaidSnapshot::query()->create([
            'snapshot_id' => $raidSnap->id,
            'member_id' => $member->id,
            'expansions' => [
                ['expansion' => ['id' => 510, 'name' => 'Midnight'], 'instances' => [
                    ['instance' => ['id' => 1400, 'name' => 'Mirrorhall'], 'modes' => [
                        ['difficulty' => ['type' => 'HEROIC', 'name' => 'Heroic'], 'progress' => [
                            'completed_count' => 2, 'total_count' => 2,
                            'encounters' => $heroicAllCleared,
                        ]],
                    ]],
                ]],
            ],
        ]);
    }

    $user = User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);
    $resp = $this->actingAs($user)->get('/dashboard');
    $resp->assertOk();

    // Insights footer surfaces the ilvl gap + AOTC clear.
    $resp->assertSee('Mythic Team avg ilvl 660 vs Heroic Team 642 (+18).');
    $resp->assertSee('Heroic Team has cleared every Heroic boss in Mirrorhall (AOTC).');
});

it('hides older-expansion raids in the breakdown so only the current tier shows', function () {
    // Snapshot carries both the previous expansion (TWW / Manaforge) and
    // the current expansion (Midnight S1 / Mirrorhall). The widget should
    // only render the latest expansion's instance, since "team progression"
    // is now scoped to the active tier only.
    $h1 = makeTeamMember('Healer-Silvermoon', TeamMapping::TEAM_HEROIC);
    // Headline rollup pulls raid_progression_json off the RIO snap, so
    // line that up with the same season the breakdown is testing.
    snapshotWithRow($h1->id, [
        'ilvl' => 645,
        'raid_progression_json' => [
            'mirrorhall' => [
                'summary' => '1/1 H',
                'total_bosses' => 1,
                'heroic_bosses_killed' => 1,
                'mythic_bosses_killed' => 0,
            ],
        ],
    ]);

    $raidSnap = Snapshot::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'captured_at' => now(),
        'source' => Snapshot::SOURCE_BLIZZARD_RAIDS,
        'payload_hash' => 'current-tier-only-test',
    ]);
    MemberRaidSnapshot::query()->create([
        'snapshot_id' => $raidSnap->id,
        'member_id' => $h1->id,
        'expansions' => [
            ['expansion' => ['id' => 503, 'name' => 'TWW'], 'instances' => [
                ['instance' => ['id' => 1296, 'name' => 'OldRaid-TWW'], 'modes' => [
                    ['difficulty' => ['type' => 'HEROIC', 'name' => 'Heroic'], 'progress' => [
                        'completed_count' => 1, 'total_count' => 1,
                        'encounters' => [['encounter' => ['id' => 91, 'name' => 'OldBoss-TWW'], 'completed_count' => 1, 'last_kill_timestamp' => 1]],
                    ]],
                ]],
            ]],
            ['expansion' => ['id' => 510, 'name' => 'Midnight'], 'instances' => [
                ['instance' => ['id' => 1400, 'name' => 'Mirrorhall'], 'modes' => [
                    ['difficulty' => ['type' => 'HEROIC', 'name' => 'Heroic'], 'progress' => [
                        'completed_count' => 1, 'total_count' => 1,
                        'encounters' => [['encounter' => ['id' => 101, 'name' => 'CurrentBoss-MN'], 'completed_count' => 1, 'last_kill_timestamp' => 1]],
                    ]],
                ]],
            ]],
        ],
    ]);

    $user = User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);
    $resp = $this->actingAs($user)->get('/dashboard');
    $resp->assertOk();
    $resp->assertSee('Mirrorhall');
    $resp->assertSee('CurrentBoss-MN');
    $resp->assertDontSee('OldRaid-TWW');
    $resp->assertDontSee('OldBoss-TWW');
});


it('caps the heroic team breakdown at heroic, dropping mythic encounters', function () {
    $crossover = makeTeamMember('Crossover-Silvermoon', TeamMapping::TEAM_HEROIC);
    snapshotWithRow($crossover->id, ['ilvl' => 642]);

    $raidSnap = Snapshot::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'captured_at' => now(),
        'source' => Snapshot::SOURCE_BLIZZARD_RAIDS,
        'payload_hash' => 'breakdown-cap-test',
    ]);
    MemberRaidSnapshot::query()->create([
        'snapshot_id' => $raidSnap->id,
        'member_id' => $crossover->id,
        'expansions' => [
            ['expansion' => ['id' => 503, 'name' => 'TWW'], 'instances' => [
                ['instance' => ['id' => 1296, 'name' => 'Manaforge Omega'], 'modes' => [
                    ['difficulty' => ['type' => 'HEROIC', 'name' => 'Heroic'], 'progress' => [
                        'completed_count' => 1, 'total_count' => 1,
                        'encounters' => [['encounter' => ['id' => 1, 'name' => 'Plexus Sentinel'], 'completed_count' => 1, 'last_kill_timestamp' => 1]],
                    ]],
                    ['difficulty' => ['type' => 'MYTHIC', 'name' => 'Mythic'], 'progress' => [
                        'completed_count' => 1, 'total_count' => 1,
                        'encounters' => [['encounter' => ['id' => 99, 'name' => 'MythicOnlyBoss'], 'completed_count' => 1, 'last_kill_timestamp' => 1]],
                    ]],
                ]],
            ]],
        ],
    ]);

    $user = User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);
    $resp = $this->actingAs($user)->get('/dashboard');

    $resp->assertOk();
    $resp->assertSee('Plexus Sentinel');
    $resp->assertDontSee('MythicOnlyBoss');
    $resp->assertDontSee('1/1 M');
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
        ->assertRedirect('/admin/sync')
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

    $this->actingAs($user)->post('/admin/raiderio/sync')->assertRedirect('/admin/sync');
    $this->actingAs($user)
        ->post('/admin/raiderio/sync')
        ->assertRedirect('/admin/sync')
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
        ->assertRedirect('/admin/sync')
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
        ->assertRedirect('/admin/sync')
        ->assertSessionHas('status', fn ($s) => str_contains($s, 'sync started'));

    // afterResponse() runs the dispatched job inline at end-of-request
    // in the test harness, so the snapshot should already exist.
    Http::assertSentCount(1);
    expect(Snapshot::query()->where('source', Snapshot::SOURCE_RAIDERIO)->count())->toBe(2);
});

it('rejects a concurrent press while another sync is running', function () {
    makeTeamMember('Sheday-Silvermoon', TeamMapping::TEAM_HEROIC);
    Http::fake();

    // Pretend another worker is already inside the importer by holding
    // the mutex manually before the request runs.
    $lock = Cache::lock(SyncStatus::raiderioMutexKey(), 60);
    expect($lock->get())->toBeTrue();

    $user = User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);

    $this->actingAs($user)
        ->post('/admin/raiderio/sync')
        ->assertRedirect('/admin/sync')
        ->assertSessionHas('status', fn ($s) => str_contains($s, 'already running'));

    // The blocked caller didn't burn an HTTP call OR a rate-limit token.
    Http::assertNothingSent();
    expect(RateLimiter::attempts('raiderio-sync:user:' . $user->id))->toBe(0);

    $lock->release();
});

