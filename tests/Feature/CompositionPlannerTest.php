<?php

use App\Models\EventSignup;
use App\Models\Member;
use App\Models\RaidEvent;
use App\Models\TeamMapping;
use App\Models\User;
use App\Models\WclActorParse;
use App\Models\WclFight;
use App\Models\WclReport;
use App\Services\Composition\SpecRoleMap;
use App\Services\Composition\TeamCompositionBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
        'raidhelper.teams.heroic' => ['label' => 'Heroic', 'channel_id' => 'CH-H', 'raid_days' => [2, 4]],
        'raidhelper.teams.mythic' => ['label' => 'Mythic', 'channel_id' => 'CH-M', 'raid_days' => [3, 7]],
    ]);
});

function compMember(string $name, string $team, string $class = 'PRIEST'): Member
{
    return Member::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'name' => $name,
        'class' => $class,
        'level' => 80,
        'rank_index' => 5,
        'team' => $team,
        'status' => Member::STATUS_ACTIVE,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);
}

function compFight(int $daysAgo = 2, int $difficulty = WclFight::DIFFICULTY_HEROIC): WclFight
{
    $report = WclReport::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'code' => substr(md5(uniqid('', true)), 0, 8),
        'title' => 'Test',
        'start_time' => now()->subDays($daysAgo),
        'captured_at' => now(),
    ]);
    return WclFight::query()->create([
        'wcl_report_id' => $report->id, 'fight_id' => 1,
        'encounter_id' => 100, 'name' => 'Plexus Sentinel',
        'difficulty' => $difficulty, 'kill' => true,
        'start_time' => now()->subDays($daysAgo),
    ]);
}

function compParse(WclFight $fight, Member $m, ?int $pct, ?string $spec = null, ?string $role = WclActorParse::ROLE_DPS): WclActorParse
{
    return WclActorParse::query()->create([
        'wcl_fight_id' => $fight->id,
        'member_id' => $m->id,
        'actor_name' => $m->name,
        'actor_class' => $m->class,
        'actor_spec' => $spec,
        'role' => $role,
        'parse_percentile' => $pct,
    ]);
}

function compOfficer(): User
{
    return User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);
}

// ----------------------------------------------------------------------------
// SpecRoleMap
// ----------------------------------------------------------------------------

it('SpecRoleMap classifies known specs into the right role', function (string $class, string $spec, string $expected) {
    expect(SpecRoleMap::role($class, $spec))->toBe($expected);
})->with([
    'protection paladin' => ['paladin', 'protection', SpecRoleMap::ROLE_TANK],
    'holy paladin'       => ['paladin', 'holy',       SpecRoleMap::ROLE_HEALER],
    'retribution'        => ['paladin', 'retribution', SpecRoleMap::ROLE_MELEE],
    'frost mage'         => ['mage', 'frost', SpecRoleMap::ROLE_RANGED],
    'survival hunter'    => ['hunter', 'survival', SpecRoleMap::ROLE_MELEE],
    'beast mastery'      => ['hunter', 'beast mastery', SpecRoleMap::ROLE_RANGED],
    'devastation evoker' => ['evoker', 'devastation', SpecRoleMap::ROLE_RANGED],
    'preservation evoker' => ['evoker', 'preservation', SpecRoleMap::ROLE_HEALER],
    'mixed-case class'   => ['DemonHunter', 'Vengeance', SpecRoleMap::ROLE_TANK],
]);

it('SpecRoleMap returns null for unknown class+spec', function () {
    expect(SpecRoleMap::role('shaman', 'mistweaver'))->toBeNull();
    expect(SpecRoleMap::role(null, 'frost'))->toBeNull();
    expect(SpecRoleMap::role('mage', null))->toBeNull();
});

// ----------------------------------------------------------------------------
// TeamCompositionBuilder
// ----------------------------------------------------------------------------

it('builds buckets keyed by inferred role with members sorted by avg parse desc', function () {
    $tank = compMember('Tank', TeamMapping::TEAM_HEROIC, 'WARRIOR');
    $heal = compMember('Heal', TeamMapping::TEAM_HEROIC, 'PRIEST');
    $dps1 = compMember('Dps1', TeamMapping::TEAM_HEROIC, 'MAGE');
    $dps2 = compMember('Dps2', TeamMapping::TEAM_HEROIC, 'MAGE');

    $f = compFight(2);
    compParse($f, $tank, null, 'protection', WclActorParse::ROLE_TANK);
    compParse($f, $heal, 90,  'holy',       WclActorParse::ROLE_HEALER);
    compParse($f, $dps1, 60,  'frost',      WclActorParse::ROLE_DPS);
    compParse($f, $dps2, 80,  'frost',      WclActorParse::ROLE_DPS);

    $members = Member::query()->whereIn('team', [TeamMapping::TEAM_HEROIC])->get();
    $buckets = (new TeamCompositionBuilder)->build($members, days: 14);

    expect(array_keys($buckets))->toContain(SpecRoleMap::ROLE_TANK, SpecRoleMap::ROLE_HEALER, SpecRoleMap::ROLE_RANGED);

    // Ranged bucket has dps2 (80) before dps1 (60).
    $ranged = $buckets[SpecRoleMap::ROLE_RANGED];
    expect($ranged[0]['member']->name)->toBe('Dps2');
    expect($ranged[1]['member']->name)->toBe('Dps1');

    // Tank has no ranked parse (null pct) but still surfaces, with avg=null.
    expect($buckets[SpecRoleMap::ROLE_TANK][0]['avg_parse'])->toBeNull();
    expect($buckets[SpecRoleMap::ROLE_TANK][0]['parses_count'])->toBe(0);
});

it('respects the days window when aggregating parses', function () {
    $m = compMember('Old', TeamMapping::TEAM_HEROIC, 'MAGE');
    $oldFight = compFight(daysAgo: 30);
    compParse($oldFight, $m, 90, 'frost');

    $members = Member::query()->whereIn('team', [TeamMapping::TEAM_HEROIC])->get();
    $buckets = (new TeamCompositionBuilder)->build($members, days: 14);

    // The 30-day-old parse is outside the 14d window, so the member
    // gets bucketed but with no parse stats (and into the unknown
    // bucket because we couldn't infer their spec from any in-window
    // parse).
    $row = $buckets['unknown'][0];
    expect($row['parses_count'])->toBe(0);
    expect($row['avg_parse'])->toBeNull();
    expect($row['latest_spec'])->toBeNull();
});

it('filters by difficulty when requested', function () {
    $m = compMember('Player', TeamMapping::TEAM_HEROIC, 'MAGE');
    $hFight = compFight(difficulty: WclFight::DIFFICULTY_HEROIC);
    $mFight = compFight(difficulty: WclFight::DIFFICULTY_MYTHIC);
    compParse($hFight, $m, 60, 'frost');
    compParse($mFight, $m, 95, 'frost');

    $members = Member::query()->whereIn('team', [TeamMapping::TEAM_HEROIC])->get();

    $heroicOnly = (new TeamCompositionBuilder)->build($members, days: 14, difficulties: [WclFight::DIFFICULTY_HEROIC]);
    expect($heroicOnly[SpecRoleMap::ROLE_RANGED][0]['avg_parse'])->toBe(60.0);

    $mythicOnly = (new TeamCompositionBuilder)->build($members, days: 14, difficulties: [WclFight::DIFFICULTY_MYTHIC]);
    expect($mythicOnly[SpecRoleMap::ROLE_RANGED][0]['avg_parse'])->toBe(95.0);

    $any = (new TeamCompositionBuilder)->build($members, days: 14, difficulties: null);
    expect($any[SpecRoleMap::ROLE_RANGED][0]['avg_parse'])->toBe(77.5);
});

// ----------------------------------------------------------------------------
// Controller / route
// ----------------------------------------------------------------------------

it('renders the composition page with role-grouped sections', function () {
    $tank = compMember('Tankman', TeamMapping::TEAM_HEROIC, 'WARRIOR');
    $heal = compMember('Healman', TeamMapping::TEAM_HEROIC, 'PRIEST');
    $f = compFight();
    compParse($f, $tank, 70, 'protection', WclActorParse::ROLE_TANK);
    compParse($f, $heal, 90, 'holy', WclActorParse::ROLE_HEALER);

    $resp = $this->actingAs(compOfficer())->get('/composition/heroic');
    $resp->assertOk()
        ->assertSee('Heroic Composition')
        ->assertSee('Tankman')
        ->assertSee('Healman');
});

it('honours the days query string', function () {
    $resp = $this->actingAs(compOfficer())->get('/composition/heroic?days=30');
    $resp->assertOk();
    expect($resp->getContent())->toContain('value="30" selected');
});

it('honours the difficulty query string', function () {
    $resp = $this->actingAs(compOfficer())->get('/composition/mythic?difficulty=' . WclFight::DIFFICULTY_MYTHIC);
    $resp->assertOk();
    expect($resp->getContent())->toContain('value="5" selected');
});

it('falls back to the team default when difficulty is bogus', function () {
    $resp = $this->actingAs(compOfficer())->get('/composition/heroic?difficulty=lolwut');
    $resp->assertOk();
    // Heroic team default difficulty = 4 (Heroic) is selected.
    expect($resp->getContent())->toContain('value="4" selected');
});

it('falls back to a sensible window when days is bogus', function () {
    $resp = $this->actingAs(compOfficer())->get('/composition/heroic?days=999');
    $resp->assertOk();
    // 14 (default) is selected.
    expect($resp->getContent())->toContain('value="14" selected');
});

it('renders the empty state when no members are on the team at all', function () {
    $this->actingAs(compOfficer())
        ->get('/composition/heroic')
        ->assertOk()
        ->assertSee('No parses recorded for this team');
});

it('shows the Unclassified footer when members exist but have no parses', function () {
    compMember('Active', TeamMapping::TEAM_HEROIC, 'MAGE');

    $this->actingAs(compOfficer())
        ->get('/composition/heroic')
        ->assertOk()
        ->assertSee('Unclassified')
        ->assertSee('Active');
});

it('404s on an unknown team slug', function () {
    $this->actingAs(compOfficer())
        ->get('/composition/banana')
        ->assertNotFound();
});

it('403s a non-officer', function () {
    $u = User::factory()->create(['tier' => null, 'last_role_check_at' => null]);

    $this->actingAs($u)
        ->get('/composition/heroic')
        ->assertForbidden();
});

it('only includes members on the requested team', function () {
    compMember('OnTeam', TeamMapping::TEAM_HEROIC, 'MAGE');
    compMember('OffTeam', TeamMapping::TEAM_MYTHIC, 'MAGE');
    $f = compFight();
    compParse($f, Member::query()->where('name', 'OnTeam')->first(), 80, 'frost');
    compParse($f, Member::query()->where('name', 'OffTeam')->first(), 99, 'frost');

    $resp = $this->actingAs(compOfficer())->get('/composition/heroic');
    $resp->assertOk()
        ->assertSee('OnTeam')
        ->assertDontSee('OffTeam');
});

// ----------------------------------------------------------------------------
// Event-filtered composition (?event=<id>)
// ----------------------------------------------------------------------------

function compEvent(string $channelId, int $hoursFromNow = 24): RaidEvent
{
    return RaidEvent::query()->create([
        'raidhelper_event_id' => (string) random_int(1000000, 9999999),
        'server_id' => 'GUILD',
        'channel_id' => $channelId,
        'title' => 'Tonight - Test Raid',
        'starts_at' => now()->addHours($hoursFromNow),
        'ics_uid' => 'uid-' . uniqid('', true),
    ]);
}

function compSignup(RaidEvent $event, string $name, string $status = 'primary'): EventSignup
{
    return EventSignup::query()->create([
        'raid_event_id' => $event->id,
        'raidhelper_signup_id' => (string) random_int(1, 999999),
        'name' => $name,
        'status' => $status,
    ]);
}

it('lists upcoming events for the team channel in the picker', function () {
    $event = compEvent('CH-H', hoursFromNow: 24);
    compEvent('CH-M', hoursFromNow: 24); // off-channel; must NOT appear

    $resp = $this->actingAs(compOfficer())->get('/composition/heroic');
    $resp->assertOk();
    $body = $resp->getContent();
    expect($body)->toContain('Tonight - Test Raid');
    // Off-channel mythic event shouldn't be in the dropdown for heroic.
    expect(substr_count($body, 'Tonight - Test Raid'))->toBe(1);
});

it('filters composition to members signed up for the picked event', function () {
    $signedUp = compMember('Aaron-Silvermoon', TeamMapping::TEAM_HEROIC, 'MAGE');
    $alsoSignedUp = compMember('Beth-Silvermoon', TeamMapping::TEAM_HEROIC, 'PRIEST');
    $notSignedUp = compMember('Carl-Silvermoon', TeamMapping::TEAM_HEROIC, 'WARRIOR');

    $event = compEvent('CH-H');
    compSignup($event, 'Aaron');           // RaidHelper often drops realm
    compSignup($event, 'Beth-Silvermoon'); // sometimes includes it

    $resp = $this->actingAs(compOfficer())->get("/composition/heroic?event={$event->id}");
    $resp->assertOk()
        ->assertSee('Aaron-Silvermoon')
        ->assertSee('Beth-Silvermoon')
        ->assertDontSee('Carl-Silvermoon');
});

it('excludes signups with non-attending statuses (bench / declined / absent)', function () {
    compMember('Coming-Silvermoon', TeamMapping::TEAM_HEROIC, 'MAGE');
    compMember('Bench-Silvermoon', TeamMapping::TEAM_HEROIC, 'MAGE');
    compMember('Declined-Silvermoon', TeamMapping::TEAM_HEROIC, 'MAGE');
    compMember('Absent-Silvermoon', TeamMapping::TEAM_HEROIC, 'MAGE');

    $event = compEvent('CH-H');
    compSignup($event, 'Coming', 'primary');
    compSignup($event, 'Bench', 'bench');
    compSignup($event, 'Declined', 'declined');
    compSignup($event, 'Absent', 'absent');

    $resp = $this->actingAs(compOfficer())->get("/composition/heroic?event={$event->id}");
    $resp->assertOk()
        ->assertSee('Coming-Silvermoon')
        ->assertDontSee('Bench-Silvermoon')
        ->assertDontSee('Declined-Silvermoon')
        ->assertDontSee('Absent-Silvermoon');
});

it('excludes fake signups', function () {
    compMember('Real-Silvermoon', TeamMapping::TEAM_HEROIC, 'MAGE');
    compMember('Fake-Silvermoon', TeamMapping::TEAM_HEROIC, 'MAGE');

    $event = compEvent('CH-H');
    compSignup($event, 'Real');
    EventSignup::query()->create([
        'raid_event_id' => $event->id,
        'raidhelper_signup_id' => 'fake-1',
        'name' => 'Fake',
        'status' => 'primary',
        'is_fake' => true,
    ]);

    $resp = $this->actingAs(compOfficer())->get("/composition/heroic?event={$event->id}");
    $resp->assertOk()
        ->assertSee('Real-Silvermoon')
        ->assertDontSee('Fake-Silvermoon');
});

it('shows the cross-team note when more raiders signed up than are on this team', function () {
    compMember('TeamRaider-Silvermoon', TeamMapping::TEAM_HEROIC, 'MAGE');
    // Two cross-team signups (members not on the heroic team roster).
    $event = compEvent('CH-H');
    compSignup($event, 'TeamRaider');
    compSignup($event, 'OffTeamA');
    compSignup($event, 'OffTeamB');

    $resp = $this->actingAs(compOfficer())->get("/composition/heroic?event={$event->id}");
    $resp->assertOk()
        ->assertSee('1 of 3 signed up')
        ->assertSee('Cross-team raiders not on the Heroic roster');
});

it('shows an empty-state message when nobody on the team signed up', function () {
    compMember('Nobody-Silvermoon', TeamMapping::TEAM_HEROIC, 'MAGE');
    $event = compEvent('CH-H');
    compSignup($event, 'StrangerNotOnTeam');

    $resp = $this->actingAs(compOfficer())->get("/composition/heroic?event={$event->id}");
    $resp->assertOk()
        ->assertSee('No Heroic members signed up to this event');
});

it('ignores an ?event= id that belongs to a different team channel', function () {
    compMember('Member-Silvermoon', TeamMapping::TEAM_HEROIC, 'MAGE');
    $mythicEvent = compEvent('CH-M');
    compSignup($mythicEvent, 'Member');

    // We're on /composition/heroic but referring to a mythic-channel event.
    // It shouldn't apply the filter; should fall back to whole-roster.
    $resp = $this->actingAs(compOfficer())->get("/composition/heroic?event={$mythicEvent->id}");
    $resp->assertOk()
        ->assertSee('1 on roster')
        ->assertDontSee('1 of 1 signed up');
});
