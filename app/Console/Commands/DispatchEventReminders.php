<?php

namespace App\Console\Commands;

use App\Services\Discord\EventReminderDispatcher;
use Illuminate\Console\Command;

/**
 * Walks every upcoming raid event and fires pre-raid reminder pings
 * to the configured event_reminder webhook(s) for any offset that
 * falls within the current scheduler tick window.
 *
 *   php artisan events:dispatch-reminders
 *
 * Designed to run every 5 minutes via the scheduler. Idempotent via
 * event_reminder_log so a missed-and-recovered tick doesn't double-
 * post.
 */
class DispatchEventReminders extends Command
{
    protected $signature = 'events:dispatch-reminders';

    protected $description = 'Fire pre-raid reminder pings to event_reminder webhooks';

    public function handle(): int
    {
        $stats = EventReminderDispatcher::fromConfig()->dispatch();

        $this->info(sprintf(
            'Considered %d events: %d reminders fired (%d webhook posts), %d skipped (already logged), %d errored.',
            $stats['events_considered'],
            $stats['reminders_fired'],
            $stats['webhooks_posted'],
            $stats['skipped_already_logged'],
            $stats['errored'],
        ));
        return $stats['errored'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
