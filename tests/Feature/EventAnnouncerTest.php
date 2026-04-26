<?php

use App\Models\DiscordWebhook;
use App\Models\RaidEvent;
use App\Services\Discord\EventAnnouncer;
use App\Services\Discord\WebhookRouter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'raidhelper.webhook_key' => 'webhook-secret-32-chars-aaaaaaaaaa',
        'raidhelper.server_id' => '1247256415542841416',
        'raidhelper.timezone' => 'Europe/Paris',
        'raidhelper.teams' => [
            'heroic'   => ['label' => 'Heroic Raid',   'channel_id' => 'CH-HEROIC',   'raid_days' => [2, 4]],
            'mythic'   => ['label' => 'Mythic Raid',   'channel_id' => 'CH-MYTHIC',   'raid_days' => [3, 7]],
            'keynight' => ['label' => 'Keynight (M+)', 'channel_id' => 'CH-KEYNIGHT', 'raid_days' => [1]],
        ],
    ]);
});

function announcerHook(array $overrides = []): DiscordWebhook
{
    return DiscordWebhook::query()->create(array_replace([
        'label' => 'Test webhook',
        'url' => 'https://discord.com/api/webhooks/100/abc',
        'purpose' => DiscordWebhook::PURPOSE_EVENT_ANNOUNCE,
        'team_slug' => null,
        'enabled' => true,
    ], $overrides));
}

function announcerEvent(array $overrides = []): RaidEvent
{
    return RaidEvent::query()->create(array_replace([
        'raidhelper_event_id' => '1234567890',
        'server_id' => '1247256415542841416',
        'channel_id' => 'CH-HEROIC',
        'leader_name' => 'Officer',
        'title' => 'Tuesday Heroic',
        'starts_at' => CarbonImmutable::now()->addDay(),
        'ics_uid' => 'ics-1',
        'ics_sequence' => 0,
    ], $overrides));
}

function newAnnouncer(): EventAnnouncer
{
    return new EventAnnouncer(new WebhookRouter());
}

// --- service ----------------------------------------------------------

it('posts to a team-scoped webhook when the channel matches', function () {
    Http::fake(['discord.com/*' => Http::response('', 204)]);
    $hook = announcerHook(['team_slug' => 'heroic', 'url' => 'https://discord.com/api/webhooks/200/heroic']);
    $event = announcerEvent(['channel_id' => 'CH-HEROIC', 'title' => 'Heroic Tuesday']);

    $r = newAnnouncer()->announceNew($event);

    expect($r)->toMatchArray(['posted_to' => 1, 'team_slug' => 'heroic', 'error' => null]);
    Http::assertSent(fn ($req) =>
        $req->url() === 'https://discord.com/api/webhooks/200/heroic'
        && str_contains($req['content'], 'Heroic Tuesday')
        && str_contains($req['content'], 'New Heroic event')
        && str_contains($req['content'], 'Sign up: https://discord.com/channels/')
    );
    expect($hook->fresh()->last_posted_at)->not->toBeNull();
});

it('falls back to a guild-wide webhook when no team-scoped one is configured', function () {
    Http::fake(['discord.com/*' => Http::response('', 204)]);
    announcerHook(['team_slug' => null, 'url' => 'https://discord.com/api/webhooks/300/guild']);
    $event = announcerEvent(['channel_id' => 'CH-MYTHIC']);

    $r = newAnnouncer()->announceNew($event);

    expect($r['posted_to'])->toBe(1);
    expect($r['team_slug'])->toBe('mythic');
    Http::assertSent(fn ($req) => $req->url() === 'https://discord.com/api/webhooks/300/guild');
});

it('uses the generic heading for events in a channel that maps to no team', function () {
    Http::fake(['discord.com/*' => Http::response('', 204)]);
    announcerHook(['team_slug' => null]);
    $event = announcerEvent(['channel_id' => 'CH-RANDOM', 'title' => 'Random hangout']);

    newAnnouncer()->announceNew($event);

    Http::assertSent(fn ($req) =>
        str_contains($req['content'], '**New event**: Random hangout')
        && ! str_contains($req['content'], 'New Heroic')
    );
});

it('is a clean no-op when no event_announce webhook exists', function () {
    Http::fake();
    $event = announcerEvent();

    $r = newAnnouncer()->announceNew($event);

    expect($r)->toMatchArray(['posted_to' => 0, 'error' => null]);
    Http::assertNothingSent();
});

it('skips disabled webhooks', function () {
    Http::fake();
    announcerHook(['enabled' => false]);
    $event = announcerEvent(['channel_id' => 'CH-RANDOM']);

    newAnnouncer()->announceNew($event);

    Http::assertNothingSent();
});

it('reports an error when the webhook 4xxs but does not throw', function () {
    Http::fake(['discord.com/*' => Http::response('rate limited', 429)]);
    announcerHook(['team_slug' => null]);
    $event = announcerEvent(['channel_id' => 'CH-RANDOM']);

    $r = newAnnouncer()->announceNew($event);

    expect($r['posted_to'])->toBe(0);
    expect($r['error'])->toContain('429');
});

// --- webhook handler integration --------------------------------------

it('webhook handler announces a brand-new event then stays silent on subsequent edits', function () {
    Http::fake(['discord.com/*' => Http::response('', 204)]);
    announcerHook(['team_slug' => 'heroic', 'url' => 'https://discord.com/api/webhooks/200/heroic']);

    $payload = [
        'id' => '900900900',
        'serverId' => '1247256415542841416',
        'channelId' => 'CH-HEROIC',
        'channelName' => 'heroic-raid-signup',
        'channelType' => 'GUILD_TEXT',
        'leaderName' => 'Officer',
        'title' => 'Heroic Tuesday',
        'startTime' => time() + 86400,
        'endTime'   => time() + 86400 + 7200,
        'closingTime' => time() + 86400 + 7200,
        'signUps' => [],
    ];
    $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'webhook-secret-32-chars-aaaaaaaaaa'];

    // First delivery: create.
    $this->call('POST', '/api/webhook/raidhelper', [], [], [], $headers, json_encode($payload))->assertOk();
    Http::assertSentCount(1);

    // Second delivery: edit (same event id, different title). No new post.
    $payload['title'] = 'Heroic Tuesday (delayed start)';
    $this->call('POST', '/api/webhook/raidhelper', [], [], [], $headers, json_encode($payload))->assertOk();
    Http::assertSentCount(1);
});
