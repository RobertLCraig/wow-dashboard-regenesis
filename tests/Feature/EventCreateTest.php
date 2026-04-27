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
            ['id' => '1430231966686511124', 'name' => 'social-events',      'label' => 'social-events'],
            ['id' => '1247281653777301714', 'name' => 'heroic-raid-signup', 'label' => 'heroic-raid-signup'],
        ],
        'raidhelper.default_announcements' => [
            ['minutes' => 1,  'message' => 'Event starting now!'],
            ['minutes' => 30, 'message' => 'Event starting in 30 minutes!'],
        ],
        // Pin the templates the controller validates against, independent
        // of whatever the production config currently exposes.
        'raidhelper.templates' => [
            ['id' => '2', 'label' => 'Test class picker'],
            ['id' => '9', 'label' => 'Test role + spec'],
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

it('defaults the announcement-row channel to the first preset channel name when default_channel_id is empty', function () {
    // Reproduces the prod bug: env('RAID_HELPER_DEFAULT_CHANNEL_ID')
    // returns '' (not null) when the .env line is blank, so the
    // `?? $firstChannelId` fallback in the blade used to skip and the
    // reminder rows landed with an empty channel field.
    config(['raidhelper.default_channel_id' => null]);

    $resp = $this->actingAs(officer())->get(route('events.create'));

    $resp->assertOk();
    // The Alpine state initialises announcements from a server-rendered
    // JSON blob; the channel slot for each row should be the first
    // preset's `name` (social-events), not an empty string.
    $resp->assertSee('"channel":"social-events"', false);
    $resp->assertDontSee('"channel":""', false);
});

it('creates an event in duration mode and sends advancedSettings.duration', function () {
    Http::fake(['raid-helper.xyz/*' => Http::response(fakeRaidHelperEvent(), 200)]);

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
    Http::fake(['raid-helper.xyz/*' => Http::response(fakeRaidHelperEvent(), 200)]);

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
    Http::fake(['raid-helper.xyz/*' => Http::response(fakeRaidHelperEvent(), 200)]);

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

it('rejects a template id that is not in the configured allowlist', function () {
    $this->actingAs(officer())
        ->post(route('events.store'), basePayload(['template_id' => '99']))
        ->assertSessionHasErrors('template_id');
});

it('rejects a channel id that is not a numeric snowflake', function () {
    $this->actingAs(officer())
        ->post(route('events.store'), basePayload(['channel_id' => 'not-a-snowflake']))
        ->assertSessionHasErrors('channel_id');
});

it('accepts a pasted channel id outside the preset list', function () {
    Http::fake(['raid-helper.xyz/*' => Http::response(fakeRaidHelperEvent(), 200)]);

    $this->actingAs(officer())
        ->post(route('events.store'), basePayload(['channel_id' => '999888777666555444']))
        ->assertRedirect();

    Http::assertSent(fn ($r) => str_contains($r->url(), '/channels/999888777666555444/event'));
});

it('humanises a Raid-Helper 404 with a channel-access hint', function () {
    Http::fake([
        'raid-helper.xyz/*' => Http::response([
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
        ->toContain('Channel 999888777666555444')
        ->toContain('Send Messages');
});

it('sync pulls every page and upserts each event', function () {
    Http::fake([
        'raid-helper.xyz/api/v4/servers/*/events' => Http::sequence()
            ->push(['pages' => 2, 'currentPage' => 1, 'eventsOverall' => 3, 'eventsTransmitted' => 2,
                'postedEvents' => [
                    ['id' => 'e1', 'title' => 'A', 'channelId' => '1', 'leaderId' => '1', 'startTime' => time() + 100, 'endTime' => time() + 200, 'closeTime' => time() + 200, 'templateId' => '2'],
                    ['id' => 'e2', 'title' => 'B', 'channelId' => '1', 'leaderId' => '1', 'startTime' => time() + 100, 'endTime' => time() + 200, 'closeTime' => time() + 200, 'templateId' => '2'],
                ]], 200)
            ->push(['pages' => 2, 'currentPage' => 2, 'eventsOverall' => 3, 'eventsTransmitted' => 1,
                'postedEvents' => [
                    ['id' => 'e3', 'title' => 'C', 'channelId' => '1', 'leaderId' => '1', 'startTime' => time() + 100, 'endTime' => time() + 200, 'closeTime' => time() + 200, 'templateId' => '2'],
                ]], 200),
    ]);

    $this->actingAs(officer())
        ->post(route('events.sync'))
        ->assertRedirect()
        ->assertSessionHas('status', 'Synced 3 events from Raid-Helper.');

    expect(\App\Models\RaidEvent::count())->toBe(3);
    expect(\App\Models\RaidEvent::pluck('raidhelper_event_id')->all())->toEqualCanonicalizing(['e1', 'e2', 'e3']);
});

it('sync is rate-limited to one call per hour per user', function () {
    Http::fake([
        'raid-helper.xyz/*' => Http::response(['pages' => 1, 'postedEvents' => []], 200),
    ]);
    $user = officer();

    $this->actingAs($user)->post(route('events.sync'))->assertRedirect();
    $this->actingAs($user)->post(route('events.sync'))
        ->assertRedirect()
        ->assertSessionHasErrors('raidhelper');
});

it('sends announcements to the API as both array and singular fields', function () {
    Http::fake(['raid-helper.xyz/*' => Http::response(fakeRaidHelperEvent(), 200)]);

    $this->actingAs(officer())
        ->post(route('events.store'), basePayload([
            'announcements' => [
                ['minutes' => 30, 'message' => '30 mins!', 'channel' => 'heroic-raid-signup'],
                ['minutes' => 1,  'message' => 'Starting now!', 'channel' => 'heroic-raid-signup'],
            ],
        ]))
        ->assertRedirect();

    Http::assertSent(function ($r) {
        return is_array($r['announcements'] ?? null)
            && count($r['announcements']) === 2
            && $r['announcements'][0]['minutesBefore'] === 30
            && $r['announcements'][0]['message'] === '30 mins!'
            && $r['announcements'][0]['channel'] === 'heroic-raid-signup'
            && ($r['announcement']['minutesBefore'] ?? null) === 30; // first one mirrored as singular
    });
});

it('strips empty announcement rows before validation', function () {
    Http::fake(['raid-helper.xyz/*' => Http::response(fakeRaidHelperEvent(), 200)]);

    $this->actingAs(officer())
        ->post(route('events.store'), basePayload([
            'announcements' => [
                ['minutes' => '', 'message' => '', 'channel' => ''],
                ['minutes' => 60, 'message' => 'an hour!', 'channel' => 'heroic-raid-signup'],
                ['minutes' => '', 'message' => '', 'channel' => ''],
            ],
        ]))
        ->assertRedirect();

    Http::assertSent(fn ($r) => count($r['announcements'] ?? []) === 1);
});

it('rejects an announcement channel containing a leading #', function () {
    $this->actingAs(officer())
        ->post(route('events.store'), basePayload([
            'announcements' => [['minutes' => 60, 'message' => 'x', 'channel' => '#bad-name']],
        ]))
        ->assertSessionHasErrors('announcements.0.channel');
});

it('omits announcements from API payload when none provided', function () {
    Http::fake(['raid-helper.xyz/*' => Http::response(fakeRaidHelperEvent(), 200)]);

    $this->actingAs(officer())
        ->post(route('events.store'), basePayload(['announcements' => []]))
        ->assertRedirect();

    Http::assertSent(fn ($r) => ! isset($r['announcements']) && ! isset($r['announcement']));
});

it('humanises a Raid-Helper non-404 error using the title field', function () {
    Http::fake([
        'raid-helper.xyz/*' => Http::response([
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
