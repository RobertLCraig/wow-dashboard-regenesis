<?php

namespace App\Console\Commands;

use App\Services\Discord\DiscordAnnouncementsClient;
use App\Services\Discord\DiscordAnnouncementsImporter;
use Illuminate\Console\Command;

/**
 * Pulls the latest page of messages from the configured Discord
 * announcements channel into discord_announcements for the Social
 * page.
 *
 *   php artisan discord:fetch-announcements
 *
 * Short-circuits cleanly when the bot token / channel id env values
 * are unset, so a deploy without Discord bot creds doesn't error.
 */
class FetchDiscordAnnouncements extends Command
{
    protected $signature = 'discord:fetch-announcements {--limit= : Override DISCORD_ANNOUNCEMENTS_PULL_LIMIT}';

    protected $description = 'Pull recent messages from the Discord announcements channel';

    public function handle(): int
    {
        $client = DiscordAnnouncementsClient::fromConfig();
        if (! $client->isConfigured()) {
            $this->info('discord:fetch-announcements skipped (DISCORD_BOT_TOKEN / DISCORD_ANNOUNCEMENTS_CHANNEL_ID not set).');
            return self::SUCCESS;
        }

        $limit = (int) ($this->option('limit') ?? config('discord.announcements_pull_limit', 50));

        try {
            $result = (new DiscordAnnouncementsImporter($client))->pull($limit);
        } catch (\Throwable $e) {
            $this->error('discord announcements pull failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info(sprintf(
            '%d messages from Discord: %d imported, %d skipped.',
            $result['total_seen'],
            $result['imported'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
