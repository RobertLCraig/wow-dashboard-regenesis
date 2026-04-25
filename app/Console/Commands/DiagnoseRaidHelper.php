<?php

namespace App\Console\Commands;

use App\Services\RaidHelper\RaidHelperClient;
use Illuminate\Console\Command;

/**
 * Probe Raid-Helper's API to figure out where a 404 / auth failure is
 * coming from. Hits the GET endpoints (no side effects), printing
 * status code + error title for each so we can isolate whether the
 * problem is auth, server id, URL pattern, or specific to the create
 * endpoint.
 *
 *   php artisan raidhelper:diagnose
 *   php artisan raidhelper:diagnose --channel=1430231966686511124
 *
 * The --channel flag adds a synthetic POST attempt with a deliberately
 * far-future date that's immediately deleted - useful for confirming
 * whether a specific channel is reachable.
 */
class DiagnoseRaidHelper extends Command
{
    protected $signature = 'raidhelper:diagnose
        {--channel= : Channel ID to probe with a real create+delete}';

    protected $description = 'Run read-only Raid-Helper API probes to diagnose 4xx errors';

    public function handle(RaidHelperClient $client): int
    {
        $apiKey = (string) config('raidhelper.api_key');
        $serverId = (string) config('raidhelper.server_id');

        $this->line('Configured server id: ' . ($serverId ?: '<EMPTY>'));
        $this->line('Configured api key:   ' . ($apiKey ? str_repeat('*', 8) . substr($apiKey, -4) : '<EMPTY>'));
        $this->newLine();

        if ($apiKey === '' || $serverId === '') {
            $this->error('RAID_HELPER_API_KEY or RAID_HELPER_SERVER_ID not set.');
            return self::FAILURE;
        }

        $this->probe('GET /api/v3/servers/{server}/events?Page=1',
            fn () => $client->listEvents(page: 1));

        $this->probe('GET /api/v3/servers/{server}/scheduledevents',
            fn () => $client->listScheduledEvents());

        $this->probe('GET /api/v2/servers/{server}/attendance',
            fn () => $client->attendance());

        if ($channelId = $this->option('channel')) {
            $this->newLine();
            $this->line("Attempting a create+immediate-delete on channel {$channelId}...");
            $resp = $client->createEvent($channelId, [
                'leaderId' => '0',
                'templateId' => '2',
                'date' => now()->addYears(10)->format('d-m-Y'),
                'time' => '23:59',
                'title' => '[regenesis-diagnose] safe to delete',
                'description' => 'Created by raidhelper:diagnose. Will auto-delete immediately.',
            ]);
            $status = $resp->status();
            $this->renderProbeLine('POST /api/v2/servers/{server}/channels/' . $channelId . '/event', $status, $resp->body());

            if ($resp->successful() && $resp->json('event.id')) {
                $eventId = $resp->json('event.id');
                $this->line("  -> created event {$eventId}, deleting...");
                $del = $client->deleteEvent($eventId);
                $this->renderProbeLine("DELETE /api/v2/events/{$eventId}", $del->status(), $del->body());
            }
        }

        $this->newLine();
        $this->line('Done. Look for 200 on the GET probes - if any are 4xx, the auth/server ID is wrong.');
        $this->line('If all GETs are 200 but the channel POST is 404, the bot lacks access to that specific channel.');

        return self::SUCCESS;
    }

    private function probe(string $label, \Closure $call): void
    {
        try {
            $resp = $call();
            $this->renderProbeLine($label, $resp->status(), $resp->body());
        } catch (\Throwable $e) {
            $this->error("[ EXC ] {$label} -> " . $e->getMessage());
        }
    }

    private function renderProbeLine(string $label, int $status, string $body): void
    {
        $tag = $status >= 200 && $status < 300 ? "<info>[ {$status} ]</info>" : "<comment>[ {$status} ]</comment>";
        $this->line("{$tag} {$label}");

        if ($status < 200 || $status >= 300) {
            // Raid-Helper / Javalin returns problem-detail JSON; pull
            // out the title field if present, else show first 200 chars.
            $json = json_decode($body, true);
            $title = is_array($json) ? ($json['title'] ?? null) : null;
            $snippet = is_string($title) && $title !== '' ? $title : mb_substr($body, 0, 200);
            $this->line("       -> {$snippet}");
        }
    }
}
