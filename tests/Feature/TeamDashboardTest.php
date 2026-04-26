<?php

use App\Models\Member;
use App\Models\MemberSnapshot;
use App\Models\RaidEvent;
use App\Models\Snapshot;
use App\Models\TeamMapping;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

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
        'raidhelper.teams.heroic' => [
            'label' => 'Heroic Raid',
            'channel_id' => 'CH-HEROIC',
            'raid_days' => [2, 4],
        ],
        'raidhelper.teams.mythic' => [
            'label' => 'Mythic Raid',
            'channel_id' => 'CH-MYTHIC',
            'raid_days' => [3, 7],
        ],
        'raidhelper.teams.keynight' => [
            'label' => 'Keynight (M+)',
            'channel_id' => 'CH-KEYNIGHT',
            'raid_days' => [1],
        ],
    ]);
});

function teamMember(string $name, ?string $team, array $overrides = []): Member
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

function rioSnapshotWith(array $rows): Snapshot
{
    $snapshot = Snapshot::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'captured_at' => now(),
        'source' => Snapshot::SOURCE_RAIDERIO,
        'payload_hash' => hash('sha256', uniqid('', true)),
    ]);
    foreach ($rows as $row) {
        MemberSnapshot::query()->create(array_merge([
            'snapshot_id' => $snapshot->id,
        ], $row));
    }
    return $snapshot;
}

function teamOfficer(): User
{
    return User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);
}

it('renders the heroic team page with roster + raid summary', function () {
    $h1 = teamMember('Healer-Silvermoon', TeamMapping::TEAM_HEROIC);
    $h2 = teamMember('Trial-Silvermoon', TeamMapping::TEAM_HEROIC_TRIAL);
    teamMember('NotHeroic-Silvermoon', TeamMapping::TEAM_MYTHIC); // not on this page

    rioSnapshotWith([
        ['member_id' => $h1->id, 'ilvl' => 645, 'mplus_score' => 1100, 'mplus_keystone' => 12,
         'raid_progression_json' => ['manaforge-omega' => ['summary' => '8/8 H 2/8 M', 'mythic_bosses_killed' => 2, 'heroic_bosses_killed' => 8]]],
        ['member_id' => $h2->id, 'ilvl' => 638, 'mplus_score' => 850, 'mplus_keystone' => 10,
         'raid_progression_json' => ['manaforge-omega' => ['summary' => '8/8 H', 'mythic_bosses_killed' => 0, 'heroic_bosses_killed' => 8]]],
    ]);

    $resp = $this->actingAs(teamOfficer())->get('/dashboard/heroic');
    $resp->assertOk();
    $resp->assertSee('Heroic Team');
    $resp->assertSee('Healer-Silvermoon');
    $resp->assertSee('Trial-Silvermoon');
    $resp->assertSee('Trial');                    // trial badge on the trial member
    $resp->assertDontSee('NotHeroic-Silvermoon'); // mythic member not on this page
    $resp->assertSee('8/8 H 2/8 M');              // best raid summary across team
    $resp->assertSee('645');                      // top ilvl shown in roster
});

it('renders the mythic team page with mythic + mythic_trial members rolled up', function () {
    $m1 = teamMember('Bruiser-Silvermoon', TeamMapping::TEAM_MYTHIC);
    $m2 = teamMember('MythTrial-Silvermoon', TeamMapping::TEAM_MYTHIC_TRIAL);
    teamMember('Hero-Silvermoon', TeamMapping::TEAM_HEROIC);

    $resp = $this->actingAs(teamOfficer())->get('/dashboard/mythic');
    $resp->assertOk();
    $resp->assertSee('Mythic Team');
    $resp->assertSee('Bruiser-Silvermoon');
    $resp->assertSee('MythTrial-Silvermoon');
    $resp->assertDontSee('Hero-Silvermoon');
});

it('only lists upcoming events whose channel matches the team', function () {
    teamMember('Healer-Silvermoon', TeamMapping::TEAM_HEROIC);

    RaidEvent::query()->create([
        'raidhelper_event_id' => '1', 'title' => 'Heroic Tuesday', 'channel_id' => 'CH-HEROIC',
        'starts_at' => now()->addDays(2), 'server_id' => 'g',
        'ics_uid' => 'ics-1', 'ics_sequence' => 0,
    ]);
    RaidEvent::query()->create([
        'raidhelper_event_id' => '2', 'title' => 'Mythic Wednesday', 'channel_id' => 'CH-MYTHIC',
        'starts_at' => now()->addDays(3), 'server_id' => 'g',
        'ics_uid' => 'ics-2', 'ics_sequence' => 0,
    ]);

    $resp = $this->actingAs(teamOfficer())->get('/dashboard/heroic');
    $resp->assertOk()
        ->assertSee('Heroic Tuesday')
        ->assertDontSee('Mythic Wednesday');
});

it('keynight page shows top RIO scoreboard regardless of team', function () {
    $a = teamMember('NoTeam-Silvermoon', null);
    $b = teamMember('Heroic-Silvermoon', TeamMapping::TEAM_HEROIC);

    rioSnapshotWith([
        ['member_id' => $a->id, 'ilvl' => 640, 'mplus_score' => 2400.0, 'mplus_keystone' => 18, 'raid_progression_json' => null],
        ['member_id' => $b->id, 'ilvl' => 642, 'mplus_score' => 1800.0, 'mplus_keystone' => 15, 'raid_progression_json' => null],
    ]);

    $resp = $this->actingAs(teamOfficer())->get('/dashboard/keynight');
    $resp->assertOk();
    $resp->assertSee('M+ Scoreboard');
    $resp->assertSee('NoTeam-Silvermoon');
    $resp->assertSee('Heroic-Silvermoon');
    $resp->assertSee('2,400'); // top RIO score formatted
});

it('keynight page only lists events in the keynight channel', function () {
    RaidEvent::query()->create([
        'raidhelper_event_id' => '3', 'title' => 'Monday Keynight', 'channel_id' => 'CH-KEYNIGHT',
        'starts_at' => now()->addDay(), 'server_id' => 'g',
        'ics_uid' => 'ics-3', 'ics_sequence' => 0,
    ]);
    RaidEvent::query()->create([
        'raidhelper_event_id' => '4', 'title' => 'Heroic Tuesday', 'channel_id' => 'CH-HEROIC',
        'starts_at' => now()->addDays(2), 'server_id' => 'g',
        'ics_uid' => 'ics-4', 'ics_sequence' => 0,
    ]);

    $resp = $this->actingAs(teamOfficer())->get('/dashboard/keynight');
    $resp->assertSee('Monday Keynight')
        ->assertDontSee('Heroic Tuesday');
});

it('non-officer is 403d from each team page + keynight', function () {
    $u = User::factory()->create(['tier' => null, 'last_role_check_at' => now()]);

    $this->actingAs($u)->get('/dashboard/heroic')->assertStatus(403);
    $this->actingAs($u)->get('/dashboard/mythic')->assertStatus(403);
    $this->actingAs($u)->get('/dashboard/keynight')->assertStatus(403);
});

it('team page renders cleanly with no roster data', function () {
    $resp = $this->actingAs(teamOfficer())->get('/dashboard/heroic');
    $resp->assertOk()
        ->assertSee('No members on this team yet');
});
