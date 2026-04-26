<?php

namespace App\Services\Discord;

use App\Models\DiscordWebhook;
use App\Models\EventReminderLog;
use App\Models\RaidEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Walks every upcoming event and posts pre-raid reminder pings to the
 * configured event_reminder webhook(s). Idempotent via event_reminder_log
 * so a 5-minute scheduler tick that catches the same event twice doesn't
 * double-ping.
 *
 * Reminder offsets come from config('raidhelper.reminder_offsets') as a
 * list of minutes-before values (default [60, 30, 5]). The dispatcher
 * fires an offset whenever the current time is within tick_window_minutes
 * of (start_time - offset). The window is wider than the scheduler tick
 * so a 5-minute clock skew or a missed tick still catches the offset on
 * the next run.
 *
 * Team scope is derived from the event's channel_id, same as
 * EventAnnouncer.
 */
class EventReminderDispatcher
{
    public function __construct(
        private readonly WebhookRouter $router,
        /** Minutes-before values to consider firing on each run. */
        private readonly array $offsets,
        /** Half-window (in minutes) around the firing moment. */
        private readonly int $tickWindowMinutes = 5,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            router: app(WebhookRouter::class),
            offsets: array_values(array_map('intval', (array) config('raidhelper.reminder_offsets', [60, 30, 5]))),
            tickWindowMinutes: (int) config('raidhelper.reminder_tick_window', 5),
        );
    }

    /**
     * @return array{
     *   events_considered:int,
     *   reminders_fired:int,
     *   webhooks_posted:int,
     *   skipped_already_logged:int,
     *   errored:int
     * }
     */
    public function dispatch(?CarbonImmutable $now = null): array
    {
        $now = $now ?? CarbonImmutable::now();

        // Pull events whose start_time falls anywhere in our reminder
        // horizon (max offset + a tick window). Cheap query.
        $maxOffset = $this->offsets ? max($this->offsets) : 0;
        $horizonEnd = $now->addMinutes($maxOffset + $this->tickWindowMinutes);

        $events = RaidEvent::query()
            ->where('starts_at', '>=', $now)
            ->where('starts_at', '<=', $horizonEnd)
            ->orderBy('starts_at')
            ->get();

        $stats = [
            'events_considered' => $events->count(),
            'reminders_fired' => 0,
            'webhooks_posted' => 0,
            'skipped_already_logged' => 0,
            'errored' => 0,
        ];

        foreach ($events as $event) {
            foreach ($this->offsets as $offset) {
                $fireAt = $event->starts_at->subMinutes($offset);
                $delta = abs($now->diffInMinutes($fireAt));
                if ($delta > $this->tickWindowMinutes) {
                    continue;
                }

                $alreadyLogged = EventReminderLog::query()
                    ->where('raid_event_id', $event->id)
                    ->where('minutes_before', $offset)
                    ->exists();
                if ($alreadyLogged) {
                    $stats['skipped_already_logged']++;
                    continue;
                }

                $teamSlug = $this->teamSlugForChannel($event->channel_id);
                $hooks = $this->router->routeFor(DiscordWebhook::PURPOSE_EVENT_REMINDER, $teamSlug);
                if ($hooks->isEmpty()) {
                    // Still log so a webhook configured later doesn't
                    // catch up by re-posting old reminders.
                    EventReminderLog::query()->create([
                        'raid_event_id' => $event->id,
                        'minutes_before' => $offset,
                        'posted_at' => $now,
                        'webhook_count' => 0,
                    ]);
                    continue;
                }

                $message = $this->renderMarkdown($event, $offset, $teamSlug);
                $posted = 0;
                foreach ($hooks as $hook) {
                    $r = (new DiscordWebhookPoster($hook->url))->post($message);
                    if ($r['error']) {
                        Log::warning('EventReminderDispatcher post failed', [
                            'webhook_id' => $hook->id, 'event_id' => $event->id, 'offset' => $offset, 'error' => $r['error'],
                        ]);
                        $stats['errored']++;
                        continue;
                    }
                    $hook->forceFill(['last_posted_at' => $now])->save();
                    $posted++;
                }

                EventReminderLog::query()->create([
                    'raid_event_id' => $event->id,
                    'minutes_before' => $offset,
                    'posted_at' => $now,
                    'webhook_count' => $posted,
                ]);
                $stats['reminders_fired']++;
                $stats['webhooks_posted'] += $posted;
            }
        }

        return $stats;
    }

    private function teamSlugForChannel(?string $channelId): ?string
    {
        if (! $channelId) {
            return null;
        }
        foreach ((array) config('raidhelper.teams', []) as $slug => $cfg) {
            if (($cfg['channel_id'] ?? null) === $channelId) {
                return (string) $slug;
            }
        }
        return null;
    }

    private function renderMarkdown(RaidEvent $event, int $offsetMinutes, ?string $teamSlug): string
    {
        $tz = config('raidhelper.timezone', 'Europe/London');
        $when = $event->starts_at?->setTimezone($tz)->format('D d M H:i T') ?? 'TBC';
        $heading = match (true) {
            $offsetMinutes <= 5  => '**Starting in a few minutes**',
            $offsetMinutes < 60  => "**Starting in {$offsetMinutes} minutes**",
            $offsetMinutes === 60 => '**Starting in an hour**',
            default => sprintf('**Starting in %d minutes**', $offsetMinutes),
        };
        $teamPrefix = $teamSlug ? ucfirst($teamSlug) . ': ' : '';

        return "{$heading}\n{$teamPrefix}{$event->title}\n{$when}\nSign up: {$event->discordJumpUrl()}";
    }
}
