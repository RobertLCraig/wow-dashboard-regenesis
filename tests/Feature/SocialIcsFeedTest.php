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

it('404s an unknown calendar token on the social feed', function () {
    $this->get('/calendar/social/no-such-token.ics')->assertNotFound();
});

it('returns text/calendar with the user\'s upcoming raid events', function () {
    $user = User::factory()->create([
        'tier' => 'officer',
        'last_role_check_at' => now(),
        'calendar_token' => 'tok-feed',
    ]);

    RaidEvent::query()->create([
        'raidhelper_event_id' => 'rh-feed',
        'channel_id' => '111',
        'server_id' => '222',
        'title' => 'Mythic Manaforge',
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHours(3),
        'closing_at' => now()->addDays(2)->subHour(),
        'ics_uid' => 'rh-feed@regenesis.local',
        'last_synced_at' => now(),
    ]);

    $resp = $this->get('/calendar/social/tok-feed.ics');
    $resp->assertOk();
    expect($resp->headers->get('content-type'))->toContain('text/calendar');

    $body = $resp->getContent();
    expect($body)->toContain('Mythic Manaforge');
    expect($body)->toContain('BEGIN:VCALENDAR');
});

it('includes upcoming Darkmoon Faire as an all-day VEVENT', function () {
    User::factory()->create([
        'tier' => 'officer',
        'last_role_check_at' => now(),
        'calendar_token' => 'tok-faire',
    ]);

    $body = $this->get('/calendar/social/tok-faire.ics')->getContent();
    expect($body)->toContain('Darkmoon Faire');
    // Date-only events in ICS use VALUE=DATE on DTSTART/DTEND.
    expect($body)->toContain('DTSTART;VALUE=DATE');
    expect($body)->toContain('DTEND;VALUE=DATE');
});

it('serves a 304 when the If-None-Match etag matches', function () {
    User::factory()->create([
        'tier' => 'officer',
        'last_role_check_at' => now(),
        'calendar_token' => 'tok-etag',
    ]);

    $first = $this->get('/calendar/social/tok-etag.ics');
    $first->assertOk();
    $etag = $first->headers->get('ETag');
    expect($etag)->not->toBeNull();

    $second = $this->withHeaders(['If-None-Match' => $etag])->get('/calendar/social/tok-etag.ics');
    $second->assertStatus(304);
});

it('serves the public world-events feed without authentication', function () {
    $resp = $this->get('/calendar/world.ics');
    $resp->assertOk();
    expect($resp->headers->get('content-type'))->toContain('text/calendar');
    expect($resp->headers->get('cache-control'))->toContain('public');

    $body = $resp->getContent();
    expect($body)->toContain('Darkmoon Faire');
    expect($body)->toContain('BEGIN:VCALENDAR');
});

it('the public world feed honours If-None-Match', function () {
    $first = $this->get('/calendar/world.ics');
    $first->assertOk();
    $etag = $first->headers->get('ETag');

    $second = $this->withHeaders(['If-None-Match' => $etag])->get('/calendar/world.ics');
    $second->assertStatus(304);
});

it('shows the Subscribe (.ics) link on the Social page when the user has a calendar_token', function () {
    $user = User::factory()->create([
        'tier' => 'officer',
        'last_role_check_at' => now(),
        'calendar_token' => 'tok-subscribe',
    ]);

    $this->actingAs($user)->get('/dashboard/social')
        ->assertOk()
        ->assertSee('Subscribe (.ics)')
        ->assertSee('/calendar/social/tok-subscribe.ics');
});
