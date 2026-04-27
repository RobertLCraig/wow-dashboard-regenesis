<?php

use App\Models\EventSignup;
use App\Models\RaidEvent;
use App\Models\User;
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
    ]);
});

function inviteOfficer(): User
{
    return User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);
}

function inviteEvent(): RaidEvent
{
    return RaidEvent::query()->create([
        'raidhelper_event_id' => 'rh-invite',
        'server_id' => '1247256415542841416',
        'channel_id' => 'channel-1',
        'title' => 'Mythic Manaforge',
        'starts_at' => now()->addDays(2),
        'ics_uid' => 'rh-invite@regenesis.local',
    ]);
}

function addSignup(RaidEvent $e, string $name, string $bucket, int $position): EventSignup
{
    return EventSignup::query()->create([
        'raid_event_id' => $e->id,
        'raidhelper_signup_id' => 'sig-' . $position . '-' . $name,
        'name' => $name,
        'class_name' => $bucket,
        'spec_name' => null,
        'role' => null,
        'status' => 'signed',
        'position' => $position,
    ]);
}

it('renders an /invite line for each signed-up player and excludes Absence / Bench / Declined', function () {
    $e = inviteEvent();
    addSignup($e, 'Sheday',     'Healer',   1);
    addSignup($e, 'Tute',       'Ranged',   2);
    addSignup($e, 'Aakervik',   'Tank',     3);
    addSignup($e, 'GhostRider', 'Absence',  4);   // excluded
    addSignup($e, 'BenchSitter','Bench',    5);   // excluded
    addSignup($e, 'NoThanks',   'Declined', 6);   // excluded

    $resp = $this->actingAs(inviteOfficer())->get("/events/{$e->id}");
    $resp->assertOk()
        ->assertSee('Invite macros')
        ->assertSee('/invite Sheday', false)
        ->assertSee('/invite Tute', false)
        ->assertSee('/invite Aakervik', false)
        ->assertDontSee('/invite GhostRider', false)
        ->assertDontSee('/invite BenchSitter', false)
        ->assertDontSee('/invite NoThanks', false);
});

it('cleans up parenthetical alts and slash-separated nicknames before invite', function () {
    $e = inviteEvent();
    addSignup($e, 'Rohan,drawmedomes(Larasala)', 'Ranged', 1);
    addSignup($e, 'Arianne/Allie',                'Healer', 2);
    addSignup($e, 'Knicksier',                    'Melee',  3);

    $resp = $this->actingAs(inviteOfficer())->get("/events/{$e->id}");
    $resp->assertOk()
        ->assertSee('/invite Rohan', false)
        ->assertSee('/invite Arianne', false)
        ->assertSee('/invite Knicksier', false);
});

it('shows the excluded counts in the header', function () {
    $e = inviteEvent();
    addSignup($e, 'A', 'Healer',   1);
    addSignup($e, 'B', 'Absence',  2);
    addSignup($e, 'C', 'Absence',  3);
    addSignup($e, 'D', 'Bench',    4);

    $resp = $this->actingAs(inviteOfficer())->get("/events/{$e->id}");
    $resp->assertOk()
        ->assertSee('1 to invite')
        ->assertSee('Absence 2')
        ->assertSee('Bench 1');
});

it('renders a "nothing to invite" hint when every signup is filtered out', function () {
    $e = inviteEvent();
    addSignup($e, 'OnlyDeclined', 'Declined', 1);

    $resp = $this->actingAs(inviteOfficer())->get("/events/{$e->id}");
    $resp->assertOk()->assertSee('Nothing to invite');
});
