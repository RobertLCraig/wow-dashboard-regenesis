<?php

namespace App\Services\Discord;

use App\Models\DiscordWebhook;
use App\Models\RaidEvent;
use Illuminate\Support\Facades\Log;

/**
 * Posts a "new raid posted" message to the configured event_announce
 * webhook(s) whenever Raid-Helper tells us about a fresh event.
 *
 * Team scope is derived from the event's channel_id by reverse-mapping
 * config('raidhelper.teams').*.channel_id. An event in the heroic
 * channel routes to the heroic-scoped announce webhook; if none is
 * configured, WebhookRouter falls back to a guild-wide one.
 *
 * No-op when no matching webhook exists - many guilds will adopt this
 * incrementally and we don't want a missing config row to error.
 */
class EventAnnouncer
{
    public function __construct(
        private readonly WebhookRouter $router,
    ) {}

    /**
     * @return array{posted_to: int, team_slug: ?string, error: ?string}
     */
    public function announceNew(RaidEvent $event): array
    {
        $teamSlug = $this->teamSlugForChannel($event->channel_id);
        $hooks = $this->router->routeFor(DiscordWebhook::PURPOSE_EVENT_ANNOUNCE, $teamSlug);

        if ($hooks->isEmpty()) {
            return ['posted_to' => 0, 'team_slug' => $teamSlug, 'error' => null];
        }

        $message = $this->renderMarkdown($event, $teamSlug);
        $postedTo = 0;
        $lastError = null;

        foreach ($hooks as $hook) {
            $r = (new DiscordWebhookPoster($hook->url))->post($message);
            if ($r['error']) {
                Log::warning('EventAnnouncer post failed', [
                    'webhook_id' => $hook->id, 'event_id' => $event->id, 'error' => $r['error'],
                ]);
                $lastError = $r['error'];
                continue;
            }
            $hook->forceFill(['last_posted_at' => now()])->save();
            $postedTo++;
        }

        return ['posted_to' => $postedTo, 'team_slug' => $teamSlug, 'error' => $lastError];
    }

    /**
     * Reverse-lookup channel_id -> team slug via config. Returns null
     * for events posted in a channel we don't recognise; the router
     * then falls back to guild-wide.
     */
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

    private function renderMarkdown(RaidEvent $event, ?string $teamSlug): string
    {
        $tz = config('raidhelper.timezone', 'Europe/London');
        $when = $event->starts_at?->setTimezone($tz)->format('D d M H:i T') ?? 'TBC';
        $heading = $teamSlug
            ? '**New ' . ucfirst($teamSlug) . ' event**'
            : '**New event**';

        $lines = [
            "{$heading}: {$event->title}",
            $when,
        ];
        if ($event->leader_name) {
            $lines[] = "Lead: {$event->leader_name}";
        }
        $lines[] = 'Sign up: ' . $event->discordJumpUrl();

        return implode("\n", $lines);
    }
}
