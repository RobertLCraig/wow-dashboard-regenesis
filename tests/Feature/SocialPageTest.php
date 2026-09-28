<?php

use App\Models\DiscordAnnouncement;
use App\Models\RaidEvent;
use App\Models\User;
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
    DiscordAnnouncement::query()->create([
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

it('shows image attachments as thumbnails, including on a post with no text', function () {
    DiscordAnnouncement::query()->create([
        'discord_message_id' => '3',
        'guild_id' => 'g',
        'channel_id' => 'c',
        'author_username' => 'GuildHerald',
        'content' => '',
        'attachments' => [[
            'id' => 'att-1',
            'filename' => 'raid-roster.png',
            'content_type' => 'image/png',
            'url' => 'https://cdn.discordapp.com/attachments/c/att-1/raid-roster.png',
            'proxy_url' => 'https://media.discordapp.net/attachments/c/att-1/raid-roster.png',
        ]],
        'posted_at' => now()->subHour(),
        'fetched_at' => now(),
    ]);

    $resp = $this->actingAs(socialOfficer())->get('/dashboard/social');
    $resp->assertOk()
        ->assertSee('Latest from Discord')
        ->assertSee('media.discordapp.net/attachments/c/att-1/raid-roster.png', escape: false)
        ->assertSee('alt="raid-roster.png"', escape: false);
});

it('drops Discord announcements outside the configured window', function () {
    config(['discord.announcements_window_days' => 30]);

    DiscordAnnouncement::query()->create([
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
    RaidEvent::query()->create([
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

it('draws a long event as one bar per week instead of a chip in every day cell', function () {
    // Mid-March is a quiet stretch of the world-events calendar, so the
    // lanes are free and this bar cannot be pushed into "+n more".
    $monday = CarbonImmutable::parse('2027-03-15')->startOfWeek()->setTime(9, 0);
    $this->travelTo($monday);

    $start = $monday->addDays(2)->startOfDay();  // Wednesday of week 1

    RaidEvent::query()->create([
        'raidhelper_event_id' => 'rh-long',
        'channel_id' => '111', 'server_id' => '222',
        'title' => 'Seventeen Day Bender',
        'starts_at' => $start,
        'ends_at' => $start->addDays(16),
        'closing_at' => $start->subHour(),
        'ics_uid' => 'rh-long@regenesis.local',
        'last_synced_at' => $monday,
    ]);

    $body = $this->actingAs(socialOfficer())->get('/dashboard/social?view=grid')->assertOk()->getContent();

    // Wed-Sun, a whole week, then Mon-Fri: three bars, not seventeen chips.
    expect(substr_count($body, 'title="Seventeen Day Bender'))->toBe(3)
        ->and($body)->toContain('grid-column: 3 / span 5')   // week 1, Wed to Sun
        ->and($body)->toContain('grid-column: 1 / span 7')   // week 2, the full week
        ->and($body)->toContain('grid-column: 1 / span 5');  // week 3, Mon to Fri
});

it('carries a week-crossing event onto the next week row', function () {
    $monday = CarbonImmutable::parse('2027-03-15')->startOfWeek()->setTime(9, 0);
    $this->travelTo($monday);

    $start = $monday->addDays(5)->startOfDay();  // Saturday of week 1

    RaidEvent::query()->create([
        'raidhelper_event_id' => 'rh-cross',
        'channel_id' => '111', 'server_id' => '222',
        'title' => 'Weekend Crossover',
        'starts_at' => $start,
        'ends_at' => $start->addDays(3),  // Tuesday of week 2
        'closing_at' => $start->subHour(),
        'ics_uid' => 'rh-cross@regenesis.local',
        'last_synced_at' => $monday,
    ]);

    $body = $this->actingAs(socialOfficer())->get('/dashboard/social?view=grid')->assertOk()->getContent();

    expect(substr_count($body, 'title="Weekend Crossover'))->toBe(2)
        ->and($body)->toContain('grid-column: 6 / span 2')   // Sat-Sun, runs off the end
        ->and($body)->toContain('grid-column: 1 / span 2');  // Mon-Tue, picked up again
});

it('stacks overlapping events on separate lanes in the same week', function () {
    $monday = CarbonImmutable::parse('2027-03-15')->startOfWeek()->setTime(9, 0);
    $this->travelTo($monday);

    foreach ([['a', 'Longer Overlap', 1, 4], ['b', 'Shorter Overlap', 2, 2]] as [$id, $title, $offset, $length]) {
        RaidEvent::query()->create([
            'raidhelper_event_id' => "rh-lane-{$id}",
            'channel_id' => '111', 'server_id' => '222',
            'title' => $title,
            'starts_at' => $monday->addDays($offset)->startOfDay(),
            'ends_at' => $monday->addDays($offset + $length)->startOfDay(),
            'closing_at' => $monday,
            'ics_uid' => "rh-lane-{$id}@regenesis.local",
            'last_synced_at' => $monday,
        ]);
    }

    $body = $this->actingAs(socialOfficer())->get('/dashboard/social?view=grid')->assertOk()->getContent();

    // Longest first, so the 5-day bar takes the first lane (row 2) and
    // the 3-day one it overlaps is pushed to the second (row 3).
    expect($body)->toContain('grid-column: 2 / span 5; grid-row: 2')
        ->and($body)->toContain('grid-column: 3 / span 3; grid-row: 3');
});

it('draws the two longest world events as a handful of bars, not one per day', function () {
    // Brewfest (17 days) and Winter Veil (18 days, over the year end) are
    // the longest things the world calendar produces. Both should come out
    // as one bar per week touched, and neither should be crowded out of
    // its lane by the events that overlap it.
    foreach (['2027-09-10' => 'Brewfest', '2027-12-05' => 'Feast of Winter Veil'] as $anchor => $name) {
        $this->travelTo(CarbonImmutable::parse($anchor)->startOfWeek()->setTime(9, 0));

        $body = $this->actingAs(socialOfficer())->get('/dashboard/social?view=grid')->assertOk()->getContent();

        expect(substr_count($body, 'title="'.$name))->toBe(3, $name)
            ->and($body)->toContain('&larr;');  // continued from the week above
    }
});

it('keeps the week grid a grid in high-clarity mode, so each date stays beside its events', function () {
    // The layout flattens every .grid to a flex column in high-clarity mode
    // unless it wears .clarity-keep-grid. The week rows place bars by inline
    // grid-column, so flattened they read as empty cells, then bare numbers,
    // then undated bars. CSS is not evaluated here: this proves the opt-out
    // class is on every 7-column grid, not how a browser draws it.
    $user = socialOfficer();
    $user->forceFill(['display_mode' => User::DISPLAY_HIGH_CLARITY])->save();

    $body = $this->actingAs($user)->get('/dashboard/social?view=grid')->assertOk()->getContent();

    preg_match_all('/class="grid grid-cols-7[^"]*"/', $body, $grids);

    expect($body)->toContain('mode-high-clarity')
        ->and(count($grids[0]))->toBeGreaterThan(1);  // the Mon..Sun header plus at least one week
    foreach ($grids[0] as $class) {
        expect($class)->toContain('clarity-keep-grid');
    }
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
