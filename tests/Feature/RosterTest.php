<?php

use App\Models\AltGroup;
use App\Models\Member;
use App\Models\MemberSnapshot;
use App\Models\Snapshot;
use App\Models\TeamMapping;
use App\Models\User;
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
    ]);
});

function rosterMember(string $name, array $overrides = []): Member
{
    return Member::query()->create(array_replace([
        'guild_key' => 'Regenesis-Silvermoon',
        'name' => $name,
        'class' => 'PRIEST',
        'level' => 80,
        'rank_index' => 5,
        'rank_name' => 'Member',
        'status' => Member::STATUS_ACTIVE,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ], $overrides));
}

function rosterOfficer(): User
{
    return User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);
}

it('renders the roster page with all members under the All filter', function () {
    rosterMember('Alpha-Silvermoon');
    rosterMember('Bravo-Silvermoon');
    rosterMember('Charlie-Silvermoon', ['status' => Member::STATUS_LEFT]); // not active

    $resp = $this->actingAs(rosterOfficer())->get('/roster');
    $resp->assertOk()
        ->assertSee('Alpha-Silvermoon')
        ->assertSee('Bravo-Silvermoon')
        ->assertDontSee('Charlie-Silvermoon');
});

it('inactive_30d filter shows only members past the 30-day cutoff', function () {
    rosterMember('Recent-Silvermoon', ['last_online_at' => now()->subDays(5)]);
    rosterMember('Stale-Silvermoon', ['last_online_at' => now()->subDays(45)]);

    $this->actingAs(rosterOfficer())
        ->get('/roster?filter=inactive_30d')
        ->assertSee('Stale-Silvermoon')
        ->assertDontSee('Recent-Silvermoon');
});

it('alts filter shows only rows with main_member_id set', function () {
    $main = rosterMember('Main-Silvermoon');
    rosterMember('Alt-Silvermoon', ['main_member_id' => $main->id]);

    $resp = $this->actingAs(rosterOfficer())->get('/roster?filter=alts');
    $resp->assertSee('Alt-Silvermoon')
        ->assertDontSee('>Main-Silvermoon<', false); // main is referenced as "Alt of" but not as a row
});

it('mains filter shows only mains-with-alt-group rows', function () {
    $altGroup = AltGroup::query()->create(['guild_key' => 'Regenesis-Silvermoon', 'group_label' => 'g1']);
    rosterMember('SoloMain-Silvermoon');                                    // no alt group => not in mains filter
    rosterMember('GroupMain-Silvermoon', ['alt_group_id' => $altGroup->id]); // in mains filter

    $resp = $this->actingAs(rosterOfficer())->get('/roster?filter=mains');
    $resp->assertSee('GroupMain-Silvermoon')
        ->assertDontSee('SoloMain-Silvermoon');
});

it('trial filter shows heroic_trial + mythic_trial members', function () {
    rosterMember('HTrial-Silvermoon', ['team' => TeamMapping::TEAM_HEROIC_TRIAL]);
    rosterMember('MTrial-Silvermoon', ['team' => TeamMapping::TEAM_MYTHIC_TRIAL]);
    rosterMember('Heroic-Silvermoon', ['team' => TeamMapping::TEAM_HEROIC]);

    $resp = $this->actingAs(rosterOfficer())->get('/roster?filter=trial');
    $resp->assertSee('HTrial-Silvermoon')
        ->assertSee('MTrial-Silvermoon')
        ->assertDontSee('Heroic-Silvermoon');
});

it('action_queue filter shows recommend_promote/demote/kick members', function () {
    rosterMember('Promote-Silvermoon', ['recommend_promote' => true]);
    rosterMember('Demote-Silvermoon', ['recommend_demote' => true]);
    rosterMember('Kick-Silvermoon', ['recommend_kick' => true]);
    rosterMember('Plain-Silvermoon');

    $resp = $this->actingAs(rosterOfficer())->get('/roster?filter=action_queue');
    $resp->assertSee('Promote-Silvermoon')
        ->assertSee('Demote-Silvermoon')
        ->assertSee('Kick-Silvermoon')
        ->assertDontSee('Plain-Silvermoon');
});

it('banned filter shows banned members and only banned members', function () {
    rosterMember('Active-Silvermoon');
    rosterMember('Banned-Silvermoon', ['status' => Member::STATUS_BANNED]);

    $resp = $this->actingAs(rosterOfficer())->get('/roster?filter=banned');
    $resp->assertSee('Banned-Silvermoon')
        ->assertDontSee('Active-Silvermoon');
});

it('an unknown filter falls back to All', function () {
    rosterMember('Alpha-Silvermoon');
    $this->actingAs(rosterOfficer())
        ->get('/roster?filter=lolwhat')
        ->assertSee('Alpha-Silvermoon');
});

it('roster row pulls ilvl + RIO + key from the latest raiderio snapshot', function () {
    $m = rosterMember('Sheday-Silvermoon');

    $snapshot = Snapshot::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'captured_at' => now(),
        'source' => Snapshot::SOURCE_RAIDERIO,
        'payload_hash' => 'h1',
    ]);
    MemberSnapshot::query()->create([
        'snapshot_id' => $snapshot->id,
        'member_id' => $m->id,
        'ilvl' => 645,
        'mplus_score' => 1500.5,
        'mplus_keystone' => 14,
    ]);

    $this->actingAs(rosterOfficer())
        ->get('/roster')
        ->assertSee('645')
        ->assertSee('1,501'); // formatted (1500.5 rounds to 1501 via number_format(0))
});

it('flag pills render for the recommend_* columns', function () {
    rosterMember('Flagged-Silvermoon', [
        'recommend_promote' => true,
        'recommend_kick' => true,
    ]);

    $this->actingAs(rosterOfficer())
        ->get('/roster')
        ->assertSee('promote')
        ->assertSee('kick');
});

it('CSV export streams the filtered set with header row', function () {
    rosterMember('Stale-Silvermoon', ['last_online_at' => now()->subDays(45), 'realm' => 'Silvermoon']);
    rosterMember('Recent-Silvermoon', ['last_online_at' => now()->subDays(5), 'realm' => 'Silvermoon']);

    $resp = $this->actingAs(rosterOfficer())->get('/roster.csv?filter=inactive_30d');
    $resp->assertOk();
    $resp->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    $body = $resp->streamedContent();
    expect($body)
        ->toContain('name,realm,class,level')   // header
        ->toContain('Stale-Silvermoon')
        ->not->toContain('Recent-Silvermoon');
});

it('non-officer is 403d from the roster page and the CSV export', function () {
    $u = User::factory()->create(['tier' => null, 'last_role_check_at' => now()]);

    $this->actingAs($u)->get('/roster')->assertStatus(403);
    $this->actingAs($u)->get('/roster.csv')->assertStatus(403);
});

it('character-links pills appear in the rendered roster row', function () {
    rosterMember('Sheday-Silvermoon');

    $this->actingAs(rosterOfficer())
        ->get('/roster')
        ->assertSee('raider.io/characters/eu/silvermoon/Sheday', false);
});
