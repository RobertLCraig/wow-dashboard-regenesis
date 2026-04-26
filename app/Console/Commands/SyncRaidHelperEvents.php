<?php

namespace App\Console\Commands;

use App\Services\RaidHelper\EventUpserter;
use App\Services\RaidHelper\RaidHelperClient;
use Illuminate\Console\Command;

/**
 * Page through Raid-Helper's event list and upsert each into the local
 * cache. Backfills events created before our webhook was wired up,
 * and serves as a safety net if a webhook delivery is ever missed.
 *
 *   php artisan raidhelper:sync-events
 *   php artisan raidhelper:sync-events --no-signups
 *
 * Designed for the scheduler (every 15 minutes); idempotent via
 * EventUpserter (same payload re-applied is a no-op besides updated_at).
 * Short-circuits cleanly when RAID_HELPER_API_KEY is unset.
 */
class SyncRaidHelperEvents extends Command
{
    protected $signature = 'raidhelper:sync-events
        {--no-signups : Skip the IncludeSignUps header (faster, but signup detail stays stale)}';

    protected $description = 'Pull every event from Raid-Helper and upsert into the local cache';

    public function handle(RaidHelperClient $client, EventUpserter $upserter): int
    {
        if (! config('raidhelper.api_key')) {
            $this->warn('RAID_HELPER_API_KEY not set; skipping.');
            return self::SUCCESS;
        }

        $includeSignups = ! $this->option('no-signups');
        $page = 1;
        $totalUpserted = 0;
        $totalPages = 1;

        do {
            $resp = $client->listEvents(page: $page, includeSignUps: $includeSignups);
            if (! $resp->successful()) {
                $this->error("Raid-Helper /events page {$page} returned {$resp->status()}: " . mb_substr($resp->body(), 0, 200));
                return self::FAILURE;
            }

            $body = $resp->json();
            $events = $body['postedEvents'] ?? [];
            $totalPages = (int) ($body['pages'] ?? 1);

            if (! is_array($events)) {
                $this->warn("Page {$page}: postedEvents missing/malformed; stopping.");
                break;
            }

            foreach ($events as $event) {
                if (! is_array($event)) {
                    continue;
                }
                try {
                    $upserter->upsert($event);
                    $totalUpserted++;
                } catch (\Throwable $e) {
                    $this->warn('Failed to upsert event ' . ($event['id'] ?? '?') . ': ' . $e->getMessage());
                }
            }

            $this->info("Page {$page}/{$totalPages}: " . count($events) . ' events.');
            $page++;
        } while ($page <= $totalPages);

        $this->info("Upserted {$totalUpserted} events total.");
        return self::SUCCESS;
    }
}
