<?php

use App\Models\RaidEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'raidhelper.timezone' => 'Europe/London',
    ]);
});

function makeRaidEvent(array $overrides = []): RaidEvent
{
    return RaidEvent::query()->create(array_replace([
        'raidhelper_event_id' => '999',
        'server_id' => 'srv',
        'channel_id' => 'ch',
        'leader_id' => 'leader',
        'title' => 'Test Raid',
        'description' => 'Bring flasks',
        'starts_at' => now()->addDay()->setTime(20, 0),
        'ends_at' => now()->addDay()->setTime(23, 0),
        'ics_uid' => 'regenesis-test-uid@regenesis-silvermoon.eu',
        'ics_sequence' => 0,
    ], $overrides));
}

it('serves a single event ics with valid signature', function () {
    $event = makeRaidEvent();
    $sig = hash_hmac('sha256', $event->ics_uid . '|' . $event->ics_sequence, config('app.key'));

    $resp = $this->get(route('event.ics', ['event' => $event, 'sig' => $sig]));

    $resp->assertOk();
    $resp->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
    $body = $resp->getContent();
    expect($body)->toContain('BEGIN:VCALENDAR');
    expect($body)->toContain('PRODID:-//Regenesis//Guild Dashboard//EN');
    expect($body)->toContain('UID:regenesis-test-uid@regenesis-silvermoon.eu');
    expect($body)->toContain('SUMMARY:Test Raid');
    expect($body)->toContain('SEQUENCE:0');
    expect($body)->toContain('END:VCALENDAR');
});

it('rejects ics requests with missing or wrong signature', function () {
    $event = makeRaidEvent();

    $this->get(route('event.ics', ['event' => $event]))->assertStatus(403);
    $this->get(route('event.ics', ['event' => $event, 'sig' => 'wrong']))->assertStatus(403);
});

it('signature is invalidated after editing (ics_sequence bumps)', function () {
    $event = makeRaidEvent();
    $oldSig = hash_hmac('sha256', $event->ics_uid . '|0', config('app.key'));

    $event->ics_sequence = 1;
    $event->save();

    $this->get(route('event.ics', ['event' => $event, 'sig' => $oldSig]))->assertStatus(403);
});

it('serves a webcal subscription feed for a known token', function () {
    $event = makeRaidEvent();
    $user = User::factory()->create([
        'discord_id' => 'sub-test',
        'calendar_token' => 'cal-token-aaaaaaaaaa',
    ]);

    $resp = $this->get(route('calendar.subscription', ['token' => 'cal-token-aaaaaaaaaa']));

    $resp->assertOk();
    $body = $resp->getContent();
    expect($body)->toContain('BEGIN:VCALENDAR');
    expect($body)->toContain('SUMMARY:Test Raid');
    // Symfony reorders Cache-Control directives alphabetically so we
    // assert presence rather than exact ordering.
    expect($resp->headers->get('Cache-Control'))
        ->toContain('private')
        ->toContain('max-age=300');
    $resp->assertHeader('ETag');
});

it('returns 404 for an unknown subscription token', function () {
    $this->get(route('calendar.subscription', ['token' => 'nope']))->assertStatus(404);
});

it('honours If-None-Match for cheap polling', function () {
    makeRaidEvent();
    User::factory()->create(['discord_id' => 'sub-test', 'calendar_token' => 'cal-token-aaaaaaaaaa']);

    $first = $this->get(route('calendar.subscription', ['token' => 'cal-token-aaaaaaaaaa']));
    $etag = $first->headers->get('ETag');

    $second = $this->withHeaders(['If-None-Match' => $etag])
        ->get(route('calendar.subscription', ['token' => 'cal-token-aaaaaaaaaa']));

    $second->assertStatus(304);
});
