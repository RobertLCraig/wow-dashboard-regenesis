<?php

use App\Models\AltGroup;
use App\Models\Member;
use App\Models\MemberAction;
use App\Models\MemberEvent;
use App\Models\MemberMplusRun;
use App\Models\MemberSnapshot;
use App\Models\Snapshot;
use App\Models\TeamMapping;
use App\Models\User;
use App\Models\WclActorParse;
use App\Models\WclFight;
use App\Models\WclReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'discord.guild_id' => '1247256415542841416',
        'discord.roles' => [
            'gm' => '1247279261434384415',
            'big6' => '1490762074584780951',
            'officer' => '1247278529163296789',
        ],
        'grm.guild_key' => 'Regenesis-Silvermoon',
        'raiderio.region' => 'eu',
        'raiderio.default_realm_slug' => 'silvermoon',
        'raiderio.realm_slugs' => [],
    ]);
});

function characterMember(string $name, array $overrides = []): Member
{
    $team = array_key_exists('team', $overrides) ? $overrides['team'] : TeamMapping::TEAM_HEROIC;
    unset($overrides['team']);

    $member = Member::query()->create(array_replace([
        'guild_key' => 'Regenesis-Silvermoon',
        'name' => $name,
        'class' => 'PALADIN',
        'level' => 80,
        'rank_index' => 5,
        'rank_name' => 'Heroic Raider',
        'status' => Member::STATUS_ACTIVE,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ], $overrides));

    if ($team !== null) {
        \App\Models\MemberTeam::query()->create([
            'member_id' => $member->id,
            'team' => $team,
            'is_override' => false,
        ]);
    }

    return $member;
}

function characterOfficer(): User
{
    return User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);
}

it('non-officer is 403d from a character page', function () {
    characterMember('Sheday-Silvermoon');
    $u = User::factory()->create(['tier' => null, 'last_role_check_at' => now()]);

    $this->actingAs($u)->get('/character/Sheday-Silvermoon')->assertStatus(403);
});

it('renders the character page with header + class + team + rank', function () {
    characterMember('Sheday-Silvermoon');

    $this->actingAs(characterOfficer())
        ->get('/character/Sheday-Silvermoon')
        ->assertOk()
        ->assertSee('Sheday-Silvermoon')
        ->assertSee('Paladin')
        ->assertSee('Heroic Raider')
        ->assertSee('Heroic');  // team label
});

it('404s an unknown character', function () {
    $this->actingAs(characterOfficer())
        ->get('/character/NoOne-Silvermoon')
        ->assertStatus(404);
});

it('renders the latest RIO snapshot card with ilvl and RIO score', function () {
    $m = characterMember('Sheday-Silvermoon');

    $snap = Snapshot::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'captured_at' => now()->subHour(),
        'source' => Snapshot::SOURCE_RAIDERIO,
        'payload_hash' => 'h',
    ]);
    MemberSnapshot::query()->create([
        'snapshot_id' => $snap->id, 'member_id' => $m->id,
        'ilvl' => 645, 'mplus_score' => 1500.5, 'mplus_keystone' => 14,
        'raid_progression_json' => ['manaforge-omega' => ['summary' => '8/8 H 3/8 M', 'mythic_bosses_killed' => 3, 'heroic_bosses_killed' => 8]],
    ]);

    $resp = $this->actingAs(characterOfficer())->get('/character/Sheday-Silvermoon');
    $resp->assertOk()
        ->assertSee('645')
        ->assertSee('1,501')        // RIO formatted
        ->assertSee('+14')          // weekly key
        ->assertSee('8/8 H 3/8 M'); // best raid summary
});

it('lists recent WCL parses with the report jump-link', function () {
    $m = characterMember('Sheday-Silvermoon');

    $report = WclReport::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'code' => 'rrrrrr',
        'title' => 'Tuesday Heroic',
        'start_time' => CarbonImmutable::parse('2026-04-22 19:30'),
        'captured_at' => now(),
    ]);
    $fight = WclFight::query()->create([
        'wcl_report_id' => $report->id, 'fight_id' => 1,
        'encounter_id' => 100, 'name' => 'Plexus Sentinel',
        'difficulty' => WclFight::DIFFICULTY_HEROIC,
        'kill' => true, 'best_percentage' => 0,
    ]);
    WclActorParse::query()->create([
        'wcl_fight_id' => $fight->id, 'member_id' => $m->id,
        'actor_name' => 'Sheday', 'actor_class' => 'PALADIN', 'role' => WclActorParse::ROLE_DPS,
        'metric_per_second' => 1500000,
    ]);

    $this->actingAs(characterOfficer())
        ->get('/character/Sheday-Silvermoon')
        ->assertOk()
        ->assertSee('Plexus Sentinel')
        ->assertSee('Heroic')
        ->assertSee('Tuesday Heroic');
});

it('lists alt cohort members linked back to their own pages', function () {
    $altGroup = AltGroup::query()->create(['guild_key' => 'Regenesis-Silvermoon', 'group_label' => 'g1']);
    $main = characterMember('Main-Silvermoon', ['alt_group_id' => $altGroup->id]);
    characterMember('Alt1-Silvermoon', ['alt_group_id' => $altGroup->id, 'main_member_id' => $main->id]);

    $resp = $this->actingAs(characterOfficer())->get('/character/Main-Silvermoon');
    $resp->assertOk()
        ->assertSee('Alt cohort')
        ->assertSee('Alt1-Silvermoon')
        ->assertDontSee('No linked alts');
});

it('shows action history with the reviewer name + decision', function () {
    $m = characterMember('Sheday-Silvermoon');
    $reviewer = User::factory()->create(['discord_username' => 'OfficerOne']);
    MemberAction::query()->create([
        'member_id' => $m->id, 'reviewed_by_user_id' => $reviewer->id,
        'action_type' => MemberAction::TYPE_PROMOTE, 'decision' => MemberAction::DECISION_ACCEPTED,
        'notes' => 'Earned it.',
    ]);

    $this->actingAs(characterOfficer())
        ->get('/character/Sheday-Silvermoon')
        ->assertOk()
        ->assertSee('promote')
        ->assertSee('accepted')
        ->assertSee('OfficerOne')
        ->assertSee('Earned it.');
});

it('shows GRM events under the Activity section', function () {
    $m = characterMember('Sheday-Silvermoon');
    MemberEvent::query()->create([
        'member_id' => $m->id,
        'type' => MemberEvent::TYPE_PROMOTED,
        'occurred_at' => now()->subDays(2),
        'payload_json' => ['from' => 'Member', 'to' => 'Heroic Raider'],
    ]);

    $this->actingAs(characterOfficer())
        ->get('/character/Sheday-Silvermoon')
        ->assertOk()
        ->assertSee('promoted')
        ->assertSee('Member', false)
        ->assertSee('Heroic Raider');
});

it('renders the alt cohort empty-state when the member is a singleton', function () {
    characterMember('Solo-Silvermoon');

    $this->actingAs(characterOfficer())
        ->get('/character/Solo-Silvermoon')
        ->assertOk()
        ->assertSee('No linked alts');
});

it('routes to characters whose realm has parentheses and accents', function () {
    // GRM stores realms verbatim, so names like "Foo-Aggra(Português)"
    // and "Foo-Drak'thul" land in the DB as-is. The route constraint
    // has to permit non-ASCII letters and punctuation past the dash.
    characterMember('Absolutely-Aggra(Português)');
    characterMember("Mikkino-Drak'thul");

    $this->actingAs(characterOfficer())
        ->get('/character/' . rawurlencode('Absolutely-Aggra(Português)'))
        ->assertOk()
        ->assertSee('Absolutely-Aggra(Português)');

    $this->actingAs(characterOfficer())
        ->get('/character/' . rawurlencode("Mikkino-Drak'thul"))
        ->assertOk()
        ->assertSee("Mikkino-Drak'thul");
});

it('shows the latest attendance stat for the character (looked up by member_name)', function () {
    $m = characterMember('Sheday-Silvermoon');

    \App\Models\AttendanceStat::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'captured_at' => now()->subDays(2),
        'member_name' => 'Sheday-Silvermoon',
        'attendance_pct' => 87.5,
        'attended_count' => 14,
        'total_count' => 16,
    ]);
    // Older row for the same character; should NOT win.
    \App\Models\AttendanceStat::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'captured_at' => now()->subWeeks(3),
        'member_name' => 'Sheday-Silvermoon',
        'attendance_pct' => 50.0,
        'attended_count' => 8,
        'total_count' => 16,
    ]);
    // Different character; should not appear here.
    \App\Models\AttendanceStat::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'captured_at' => now()->subDays(1),
        'member_name' => 'Other-Silvermoon',
        'attendance_pct' => 99.9,
        'attended_count' => 16,
        'total_count' => 16,
    ]);

    $resp = $this->actingAs(characterOfficer())->get('/character/Sheday-Silvermoon');
    $resp->assertOk();
    // The latest row's percentage is what should surface.
    $resp->assertSee('87.5');
    // The older row's percentage shouldn't appear.
    $resp->assertDontSee('50.0');
});

it('still renders the character page even when the BiS comparison service throws', function () {
    characterMember('Crash-Silvermoon');

    // Swap in a comparison service whose compareForMember always throws.
    // The controller catches it, logs, and continues rendering.
    $this->app->bind(\App\Services\Bis\BisComparisonService::class, function () {
        return new class extends \App\Services\Bis\BisComparisonService {
            public function compareForMember(\App\Models\Member $member): ?array
            {
                throw new \RuntimeException('contrived test failure');
            }
        };
    });

    $this->actingAs(characterOfficer())
        ->get('/character/Crash-Silvermoon')
        ->assertOk()
        ->assertSee('Crash-Silvermoon')
        // The BiS section should be omitted when the service throws,
        // so its header text ("BiS comparison") is not in the response.
        ->assertDontSee('BiS comparison');
});

it('renders an empty-state Mythic+ activity panel when the character has no runs', function () {
    characterMember('Quiet-Silvermoon');

    $this->actingAs(characterOfficer())
        ->get('/character/Quiet-Silvermoon')
        ->assertOk()
        ->assertSee('Mythic+ activity')
        ->assertSee('No keys completed in the last 90 days');
});

it('renders the Mythic+ activity panel with summary tiles, dungeon spread, and recent runs', function () {
    $member = characterMember('Sheday-Silvermoon');

    // Three runs spread across the trailing windows so each summary
    // tile has something to show: one in the last 7d (timed +14),
    // one between 7d and 30d (untimed +10), one between 30d and 90d
    // (timed +12). Hit two distinct dungeons for the spread chart.
    $now = CarbonImmutable::now();
    foreach ([
        ['short' => 'HoA', 'name' => 'Halls of Atonement', 'level' => 14, 'upgrades' => 1, 'completed_at' => $now->subDays(2)],
        ['short' => 'TOP', 'name' => 'Theatre of Pain',    'level' => 10, 'upgrades' => 0, 'completed_at' => $now->subDays(15)],
        ['short' => 'HoA', 'name' => 'Halls of Atonement', 'level' => 12, 'upgrades' => 2, 'completed_at' => $now->subDays(45)],
    ] as $r) {
        MemberMplusRun::query()->create([
            'member_id' => $member->id,
            'completed_at' => $r['completed_at'],
            'mythic_level' => $r['level'],
            'dungeon_id' => 391,
            'dungeon_short_name' => $r['short'],
            'dungeon_name' => $r['name'],
            'num_keystone_upgrades' => $r['upgrades'],
            'source' => MemberMplusRun::SOURCE_RECENT,
            'first_seen_at' => $r['completed_at'],
            'last_seen_at' => $r['completed_at'],
        ]);
    }

    $this->actingAs(characterOfficer())
        ->get('/character/Sheday-Silvermoon')
        ->assertOk()
        ->assertSee('Mythic+ activity')
        ->assertSee('Last 7 days')
        ->assertSee('Last 30 days')
        ->assertSee('Last 90 days')
        ->assertSee('Dungeon spread (30d)')
        // Both dungeons within 30d should appear in the spread.
        ->assertSee('HoA')
        ->assertSee('TOP')
        ->assertSee('Recent runs')
        // Highest level shown in the 90d tile should be the +14
        ->assertSee('+14');
});
