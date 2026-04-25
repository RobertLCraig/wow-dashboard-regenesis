<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'raidhelper.api_key' => 'rh-test-key',
        'raidhelper.webhook_key' => 'wh-test',
        'raidhelper.server_id' => '1247256415542841416',
        'raidhelper.default_channel_id' => '1430231966686511124',
        'raidhelper.timezone' => 'Europe/London',
        'raidhelper.channels' => [
            ['id' => '1430231966686511124', 'label' => 'social-events'],
            ['id' => '1247281653777301714', 'label' => 'heroic-raid-signup'],
        ],
        'discord.guild_id' => '1247256415542841416',
        'discord.role_cache_ttl_minutes' => 5,
    ]);
});

function officer(): User
{
    return User::factory()->create([
        'discord_id' => '900000',
        'tier' => User::TIER_OFFICER,
        'last_role_check_at' => now(),
    ]);
}

function fakeRaidHelperEvent(string $id = '999'): array
{
    return [
        'status' => 'success',
        'event' => [
            'id' => $id,
            'serverId' => '1247256415542841416',
            'channelId' => '1430231966686511124',
            'leaderId' => '900000',
            'leaderName' => 'Officer',
            'title' => 'Test Raid',
            'description' => '',
            'startTime' => time() + 86400,
            'endTime' => time() + 86400 + 7200,
            'closingTime' => time() + 86400 + 7200,
            'date' => '01-05-2026',
            'time' => '20:00',
            'templateId' => '2',
            'advancedSettings' => [],
            'classes' => [],
            'roles' => [],
            'signUps' => [],
            'lastUpdated' => time(),
        ],
    ];
}

function basePayload(array $overrides = []): array
{
    return array_replace([
        'title' => 'Wednesday Mythic',
        'description' => 'Bring pots',
        'starts_at' => now()->addDay()->format('Y-m-d\TH:i'),
        'duration_mode' => 'duration',
        'duration_minutes' => 180,
        'template_id' => '2',
        'channel_id' => '1430231966686511124',
        'leader_id' => '900000',
    ], $overrides);
}

it('creates an event in duration mode and sends advancedSettings.duration', function () {
    Http::fake(['raid-helper.dev/*' => Http::response(fakeRaidHelperEvent(), 200)]);

    $this->actingAs(officer())
        ->post(route('events.store'), basePayload(['duration_mode' => 'duration', 'duration_minutes' => 240]))
        ->assertRedirect();

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), '/channels/1430231966686511124/event')
            && ($request['advancedSettings']['duration'] ?? null) === '240';
    });
});

it('creates an event in end_time mode and converts to duration minutes', function () {
    Http::fake(['raid-helper.dev/*' => Http::response(fakeRaidHelperEvent(), 200)]);

    $start = now()->addDay()->setTime(20, 0);
    $end = $start->copy()->addHours(3)->addMinutes(30); // 210 min

    $this->actingAs(officer())
        ->post(route('events.store'), basePayload([
            'duration_mode' => 'end_time',
            'duration_minutes' => null,
            'starts_at' => $start->format('Y-m-d\TH:i'),
            'ends_at' => $end->format('Y-m-d\TH:i'),
        ]))
        ->assertRedirect();

    Http::assertSent(fn ($r) => ($r['advancedSettings']['duration'] ?? null) === '210');
});

it('creates an event in default mode without sending duration at all', function () {
    Http::fake(['raid-helper.dev/*' => Http::response(fakeRaidHelperEvent(), 200)]);

    $this->actingAs(officer())
        ->post(route('events.store'), basePayload([
            'duration_mode' => 'default',
            'duration_minutes' => null,
        ]))
        ->assertRedirect();

    // Either advancedSettings is omitted entirely or duration is absent.
    Http::assertSent(fn ($r) => ! isset($r['advancedSettings']['duration']));
});

it('rejects duration mode without duration_minutes', function () {
    $this->actingAs(officer())
        ->post(route('events.store'), basePayload([
            'duration_mode' => 'duration',
            'duration_minutes' => null,
        ]))
        ->assertSessionHasErrors('duration_minutes');
});

it('rejects end_time mode without ends_at', function () {
    $this->actingAs(officer())
        ->post(route('events.store'), basePayload([
            'duration_mode' => 'end_time',
            'duration_minutes' => null,
            'ends_at' => null,
        ]))
        ->assertSessionHasErrors('ends_at');
});

it('rejects end_time before starts_at', function () {
    $start = now()->addDay()->setTime(20, 0);
    $this->actingAs(officer())
        ->post(route('events.store'), basePayload([
            'duration_mode' => 'end_time',
            'duration_minutes' => null,
            'starts_at' => $start->format('Y-m-d\TH:i'),
            'ends_at' => $start->copy()->subHour()->format('Y-m-d\TH:i'),
        ]))
        ->assertSessionHasErrors('ends_at');
});

it('rejects a channel id that is not a numeric snowflake', function () {
    $this->actingAs(officer())
        ->post(route('events.store'), basePayload(['channel_id' => 'not-a-snowflake']))
        ->assertSessionHasErrors('channel_id');
});

it('accepts a pasted channel id outside the preset list', function () {
    Http::fake(['raid-helper.dev/*' => Http::response(fakeRaidHelperEvent(), 200)]);

    $this->actingAs(officer())
        ->post(route('events.store'), basePayload(['channel_id' => '999888777666555444']))
        ->assertRedirect();

    Http::assertSent(fn ($r) => str_contains($r->url(), '/channels/999888777666555444/event'));
});

it('humanises a Raid-Helper 404 with a channel-access hint', function () {
    Http::fake([
        'raid-helper.dev/*' => Http::response([
            'title' => 'Endpoint POST /api/v2/servers/X/channels/Y/event not found',
            'status' => 404,
        ], 404),
    ]);

    $this->actingAs(officer())
        ->post(route('events.store'), basePayload(['channel_id' => '999888777666555444']))
        ->assertRedirect();

    $errors = session('errors')->get('raidhelper');
    expect($errors[0])
        ->toContain('Endpoint POST')
        ->toContain('channel 999888777666555444')
        ->toContain('Send Messages');
});

it('humanises a Raid-Helper non-404 error using the title field', function () {
    Http::fake([
        'raid-helper.dev/*' => Http::response([
            'title' => 'Invalid template id',
            'status' => 422,
        ], 422),
    ]);

    $this->actingAs(officer())
        ->post(route('events.store'), basePayload());

    $errors = session('errors')->get('raidhelper');
    expect($errors[0])
        ->toContain('422')
        ->toContain('Invalid template id')
        ->not->toContain('Send Messages'); // 404-only hint
});
