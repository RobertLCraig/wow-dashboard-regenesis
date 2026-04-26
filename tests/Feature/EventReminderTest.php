<?php

use App\Models\DiscordWebhook;
use App\Models\EventReminderLog;
use App\Models\RaidEvent;
use App\Services\Discord\EventReminderDispatcher;
use App\Services\Discord\WebhookRouter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'raidhelper.timezone' => 'Europe/Paris',
        'raidhelper.reminder_offsets' => [60, 30, 5],
        'raidhelper.teams' => [
            'heroic' => ['label' => 'Heroic', 'channel_id' => 'CH-HEROIC'],
            'mythic' => ['label' => 'Mythic', 'channel_id' => 'CH-MYTHIC'],
        ],
    ]);
});

function reminderHook(array $overrides = []): DiscordWebhook
{
    return DiscordWebhook::query()->create(array_replace([
        'label' => 'Reminder webhook',
        'url' => 'https://discord.com/api/webhooks/100/abc',
        'purpose' => DiscordWebhook::PURPOSE_EVENT_REMINDER,
        'team_slug' => null,
        'enabled' => true,
    ], $overrides));
}

function reminderEvent(CarbonImmutable $startsAt, array $overrides = []): RaidEvent
{
    static $seq = 0;
    $seq++;
    return RaidEvent::query()->create(array_replace([
        'raidhelper_event_id' => '90000000' . $seq,
        'server_id' => '1247256415542841416',
        'channel_id' => 'CH-HEROIC',
        'title' => 'Heroic Tuesday',
        'starts_at' => $startsAt,
        'ics_uid' => 'ics-' . $seq,
        'ics_sequence' => 0,
    ], $overrides));
}

function newDispatcher(int $tickWindow = 5, array $offsets = [60, 30, 5]): EventReminderDispatcher
{
    return new EventReminderDispatcher(new WebhookRouter(), $offsets, $tickWindow);
}

it('fires a reminder when an event start matches an offset within the tick window', function () {
    Http::fake(['discord.com/*' => Http::response('', 204)]);
    reminderHook();
    $now = CarbonImmutable::parse('2026-04-26 19:00');
    Carbon::setTestNow($now);

    // Event in 30 minutes -> matches the 30-min offset.
    reminderEvent($now->addMinutes(30));

    $stats = newDispatcher()->dispatch($now);

    expect($stats['reminders_fired'])->toBe(1);
    expect($stats['webhooks_posted'])->toBe(1);
    Http::assertSent(fn ($req) => str_contains($req['content'], 'Starting in 30 minutes'));
});

it('does not double-fire the same (event, offset) on a later tick', function () {
    Http::fake(['discord.com/*' => Http::response('', 204)]);
    reminderHook();
    $now = CarbonImmutable::parse('2026-04-26 19:00');

    reminderEvent($now->addMinutes(30));

    newDispatcher()->dispatch($now);
    Http::assertSentCount(1);

    // Five minutes later, dispatcher runs again. Same (event, 30min)
    // pair is already logged; no second post.
    $stats = newDispatcher()->dispatch($now->addMinutes(5));
    expect($stats['skipped_already_logged'])->toBe(1);
    expect($stats['reminders_fired'])->toBe(0);
    Http::assertSentCount(1);
});

it('fires multiple offsets across separate ticks for the same event', function () {
    Http::fake(['discord.com/*' => Http::response('', 204)]);
    reminderHook();
    $start = CarbonImmutable::parse('2026-04-26 20:00');
    reminderEvent($start);

    // 60-min offset window
    newDispatcher()->dispatch($start->subMinutes(60));
    // 30-min offset window
    newDispatcher()->dispatch($start->subMinutes(30));
    // 5-min offset window
    newDispatcher()->dispatch($start->subMinutes(5));

    expect(EventReminderLog::query()->count())->toBe(3);
    Http::assertSentCount(3);
});

it('falls back to a guild-wide webhook when no team-scoped one is configured', function () {
    Http::fake(['discord.com/*' => Http::response('', 204)]);
    reminderHook(['team_slug' => null, 'url' => 'https://discord.com/api/webhooks/200/guild']);
    $now = CarbonImmutable::parse('2026-04-26 19:00');
    reminderEvent($now->addMinutes(30), ['channel_id' => 'CH-MYTHIC']);

    $stats = newDispatcher()->dispatch($now);

    expect($stats['reminders_fired'])->toBe(1);
    Http::assertSent(fn ($req) => $req->url() === 'https://discord.com/api/webhooks/200/guild'
        && str_contains($req['content'], 'Mythic: Heroic Tuesday'));
});

it('logs a reminder with webhook_count=0 when no webhook matches so it does not retry forever', function () {
    Http::fake();  // no webhooks configured
    $now = CarbonImmutable::parse('2026-04-26 19:00');
    reminderEvent($now->addMinutes(30));

    $stats = newDispatcher()->dispatch($now);

    expect($stats['reminders_fired'])->toBe(0);
    expect($stats['webhooks_posted'])->toBe(0);
    expect(EventReminderLog::query()->count())->toBe(1);
    expect(EventReminderLog::query()->first()->webhook_count)->toBe(0);
    Http::assertNothingSent();
});

it('does not fire on a future event that is past the largest offset', function () {
    Http::fake();
    reminderHook();
    $now = CarbonImmutable::parse('2026-04-26 19:00');
    reminderEvent($now->addHours(5));  // way past the 60-min horizon

    $stats = newDispatcher()->dispatch($now);

    expect($stats['events_considered'])->toBe(0);
    Http::assertNothingSent();
});

it('does not fire on a past event', function () {
    Http::fake();
    reminderHook();
    $now = CarbonImmutable::parse('2026-04-26 19:00');
    reminderEvent($now->subMinutes(30));  // event already started

    $stats = newDispatcher()->dispatch($now);

    expect($stats['events_considered'])->toBe(0);
});

it('artisan command runs end-to-end and reports stats', function () {
    Http::fake(['discord.com/*' => Http::response('', 204)]);
    reminderHook();
    Carbon::setTestNow($now = CarbonImmutable::parse('2026-04-26 19:00'));
    reminderEvent($now->addMinutes(30));

    $this->artisan('events:dispatch-reminders')
        ->expectsOutputToContain('1 reminders fired')
        ->assertExitCode(0);
});
