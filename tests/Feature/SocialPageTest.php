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

it('renders a "Latest from Discord" section when announcements exist', function () {
    \App\Models\DiscordAnnouncement::query()->create([
        'discord_message_id' => '1',
        'guild_id' => 'g',
        'channel_id' => 'c',
        'author_username' => 'GuildHerald',
        'content' => 'Drunken raid night this Saturday, BYOB!',
        'posted_at' => now()->subHours(2),
        'fetched_at' => now(),
    ]);

    $resp = $this->actingAs(socialOfficer())->get('/dashboard/social');
    $resp->assertOk()
        ->assertSee('Latest from Discord')
        ->assertSee('GuildHerald')
        ->assertSee('Drunken raid night this Saturday');
});

it('drops Discord announcements outside the configured window', function () {
    config(['discord.announcements_window_days' => 30]);

    \App\Models\DiscordAnnouncement::query()->create([
        'discord_message_id' => '2',
        'guild_id' => 'g',
        'channel_id' => 'c',
        'author_username' => 'OldHerald',
        'content' => 'Last expansions transmog contest',
        'posted_at' => now()->subDays(45),
        'fetched_at' => now(),
    ]);

    $resp = $this->actingAs(socialOfficer())->get('/dashboard/social');
    $resp->assertOk()->assertDontSee('Last expansions transmog contest');
});

it('hides the "Latest from Discord" section entirely when there are no recent announcements', function () {
    $resp = $this->actingAs(socialOfficer())->get('/dashboard/social');
    $resp->assertOk()->assertDontSee('Latest from Discord');
});

it('shows the quick-create panel pointed at the social-events channel', function () {
    config(['raidhelper.teams.social' => [
        'label' => 'Social Event',
        'channel_id' => '1430231966686511124',
        'raid_days' => [],
        'template_id' => '1',
    ]]);

    $resp = $this->actingAs(socialOfficer())->get('/dashboard/social');
    $resp->assertOk()
        ->assertSee('1430231966686511124', false)  // hidden channel_id input
        ->assertSee('value="1"', false);            // hidden template_id input (accept/maybe/decline)
});

it('renders a grid view when ?view=grid is set', function () {
    \App\Models\RaidEvent::query()->create([
        'raidhelper_event_id' => 'rh-grid',
        'channel_id' => '111',
        'server_id' => '222',
        'title' => 'Mythic Tuesday',
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHours(3),
        'closing_at' => now()->addDays(2)->subHour(),
        'ics_uid' => 'rh-grid@regenesis.local',
        'last_synced_at' => now(),
    ]);

    $resp = $this->actingAs(socialOfficer())->get('/dashboard/social?view=grid');
    $resp->assertOk()
        ->assertSee('Mythic Tuesday');
});

it('renders two upcoming events in chronological order', function () {
    RaidEvent::query()->create([
        'raidhelper_event_id' => 'rh-later',
        'channel_id' => '111', 'server_id' => '222',
        'title' => 'Later Raid Night',
        'starts_at' => now()->addDays(5),
        'ends_at' => now()->addDays(5)->addHours(3),
        'closing_at' => now()->addDays(5)->subHour(),
        'ics_uid' => 'rh-later@regenesis.local',
        'last_synced_at' => now(),
    ]);
    RaidEvent::query()->create([
        'raidhelper_event_id' => 'rh-sooner',
        'channel_id' => '111', 'server_id' => '222',
        'title' => 'Sooner Raid Night',
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHours(3),
        'closing_at' => now()->addDays(2)->subHour(),
        'ics_uid' => 'rh-sooner@regenesis.local',
        'last_synced_at' => now(),
    ]);

    $body = $this->actingAs(socialOfficer())->get('/dashboard/social')->assertOk()->getContent();

    expect(strpos($body, 'Sooner Raid Night'))->toBeLessThan(strpos($body, 'Later Raid Night'));
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
