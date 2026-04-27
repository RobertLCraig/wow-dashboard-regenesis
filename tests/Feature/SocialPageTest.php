<?php

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

function socialOfficer(): User
{
    return User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);
}

it('non-officer is 403d from the social page', function () {
    $user = User::factory()->create(['tier' => 'pending']);
    $this->actingAs($user)->get('/dashboard/social')->assertForbidden();
});

it('renders the social page with header + "next 60 days" copy', function () {
    $resp = $this->actingAs(socialOfficer())->get('/dashboard/social');
    $resp->assertOk()
        ->assertSee('Social')
        ->assertSee('next 60 days');
});

it('shows an upcoming Raid-Helper event in the chronological list', function () {
    RaidEvent::query()->create([
        'raidhelper_event_id' => 'rh-1',
        'channel_id' => '111',
        'server_id' => '222',
        'title' => 'Drunken Raid Night',
        'description' => 'BYOB. No mythic, just vibes.',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(3),
        'closing_at' => now()->addDays(3)->subHour(),
        'ics_uid' => 'rh-1@regenesis.local',
        'last_synced_at' => now(),
    ]);

    $resp = $this->actingAs(socialOfficer())->get('/dashboard/social');
    $resp->assertOk()
        ->assertSee('Drunken Raid Night')
        ->assertSee('No mythic, just vibes.');
});

it('shows the upcoming Darkmoon Faire from the world events calendar', function () {
    // The page fetches the next 60 days from now(); that's guaranteed
    // to span at least one Darkmoon Faire month, so the section
    // appears even with no Raid-Helper events created.
    $resp = $this->actingAs(socialOfficer())->get('/dashboard/social');
    $resp->assertOk()->assertSee('Darkmoon Faire');
});

it('renders the empty-state when the next 60 days hold no Raid-Helper events but world events exist', function () {
    // No raid_events seeded; Darkmoon Faire still surfaces, so the
    // empty-state copy should NOT appear.
    $resp = $this->actingAs(socialOfficer())->get('/dashboard/social');
    $resp->assertOk()
        ->assertSee('Darkmoon Faire')
        ->assertDontSee('Nothing scheduled in the next');
});

it('hides past Raid-Helper events even when their start is technically inside the window', function () {
    RaidEvent::query()->create([
        'raidhelper_event_id' => 'rh-old',
        'channel_id' => '111',
        'server_id' => '222',
        'title' => 'Last Tuesdays Raid',
        'starts_at' => now()->subDays(2),
        'ends_at' => now()->subDays(2)->addHours(3),
        'closing_at' => now()->subDays(2)->subHour(),
        'ics_uid' => 'rh-old@regenesis.local',
        'last_synced_at' => now(),
    ]);

    $resp = $this->actingAs(socialOfficer())->get('/dashboard/social');
    $resp->assertOk()->assertDontSee('Last Tuesdays Raid');
});
