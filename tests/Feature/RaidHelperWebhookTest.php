<?php

use App\Models\EventSignup;
use App\Models\RaidEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'raidhelper.webhook_key' => 'webhook-secret-32-chars-aaaaaaaaaa',
        'raidhelper.server_id' => '1247256415542841416',
        'raidhelper.default_channel_id' => '999000111',
    ]);
});

function webhookHeaders(): array
{
    return [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => 'webhook-secret-32-chars-aaaaaaaaaa',
    ];
}

function webhookPayload(array $overrides = []): array
{
    return array_replace([
        'id' => '1234567890',
        'serverId' => '1247256415542841416',
        'leaderId' => '111222333',
        'leaderName' => 'Officer',
        'channelId' => '999000111',
        'channelName' => 'raid-events',
        'channelType' => 'GUILD_TEXT',
        'templateId' => '2',
        'title' => 'Wednesday Mythic',
        'description' => 'Bring pots',
        'startTime' => time() + 86400,
        'endTime' => time() + 86400 + 7200,
        'closingTime' => time() + 86400 + 7200,
        'date' => '01-05-2026',
        'time' => '20:00',
        'advancedSettings' => ['duration' => '120'],
        'classes' => [],
        'roles' => [],
        'signUps' => [
            ['id' => 'su-1', 'userId' => '111111', 'name' => 'Tank McTank', 'className' => 'WARRIOR', 'specName' => 'Protection', 'role' => 'Tank', 'status' => 'signed', 'position' => 1, 'entryTime' => time()],
            ['id' => 'su-2', 'userId' => '222222', 'name' => 'Healy McHeal', 'className' => 'PRIEST', 'specName' => 'Holy', 'role' => 'Healer', 'status' => 'signed', 'position' => 2, 'entryTime' => time()],
        ],
        'lastUpdated' => time(),
        'color' => '5865F2',
    ], $overrides);
}

it('rejects webhooks without the webhook key', function () {
    $this->postJson('/api/webhook/raidhelper', webhookPayload())->assertStatus(401);
});

it('rejects webhooks with the wrong webhook key', function () {
    $this->call('POST', '/api/webhook/raidhelper', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => 'wrong-key',
    ], json_encode(webhookPayload()))->assertStatus(401);
});

it('upserts an event from a webhook payload', function () {
    $this->call('POST', '/api/webhook/raidhelper', [], [], [], webhookHeaders(), json_encode(webhookPayload()))
        ->assertStatus(200);

    expect(RaidEvent::count())->toBe(1);
    $event = RaidEvent::first();
    expect($event->raidhelper_event_id)->toBe('1234567890');
    expect($event->title)->toBe('Wednesday Mythic');
    expect($event->ics_uid)->toStartWith('regenesis-')->toEndWith('@regenesis-silvermoon.eu');
    expect($event->ics_sequence)->toBe(0);
    expect($event->signups)->toHaveCount(2);
});

it('bumps ics_sequence only when material fields change', function () {
    $payload = webhookPayload();

    $this->call('POST', '/api/webhook/raidhelper', [], [], [], webhookHeaders(), json_encode($payload))->assertStatus(200);
    $event = RaidEvent::first();
    expect($event->ics_sequence)->toBe(0);

    // Re-deliver an unchanged payload; signups change but title doesn't.
    $payload['signUps'][] = ['id' => 'su-3', 'userId' => '333', 'name' => 'New Person', 'className' => 'MAGE', 'role' => 'DPS', 'status' => 'signed', 'position' => 3, 'entryTime' => time()];
    $this->call('POST', '/api/webhook/raidhelper', [], [], [], webhookHeaders(), json_encode($payload))->assertStatus(200);
    $event->refresh();
    expect($event->ics_sequence)->toBe(0);
    expect($event->signups)->toHaveCount(3);

    // Now change the title - sequence should bump.
    $payload['title'] = 'Friday Heroic';
    $this->call('POST', '/api/webhook/raidhelper', [], [], [], webhookHeaders(), json_encode($payload))->assertStatus(200);
    $event->refresh();
    expect($event->ics_sequence)->toBe(1);
    expect($event->title)->toBe('Friday Heroic');
});

it('removes signups that are no longer in the payload', function () {
    $this->call('POST', '/api/webhook/raidhelper', [], [], [], webhookHeaders(), json_encode(webhookPayload()))->assertStatus(200);
    expect(EventSignup::count())->toBe(2);

    $payload = webhookPayload();
    $payload['signUps'] = [$payload['signUps'][0]]; // drop the healer
    $this->call('POST', '/api/webhook/raidhelper', [], [], [], webhookHeaders(), json_encode($payload))->assertStatus(200);

    expect(EventSignup::count())->toBe(1);
    expect(EventSignup::first()->name)->toBe('Tank McTank');
});

it('keeps the same ics_uid across edits', function () {
    $this->call('POST', '/api/webhook/raidhelper', [], [], [], webhookHeaders(), json_encode(webhookPayload()))->assertStatus(200);
    $original = RaidEvent::first()->ics_uid;

    $payload = webhookPayload(['title' => 'Different title']);
    $this->call('POST', '/api/webhook/raidhelper', [], [], [], webhookHeaders(), json_encode($payload))->assertStatus(200);

    expect(RaidEvent::first()->ics_uid)->toBe($original);
});
