<?php

namespace App\Console\Commands;

use App\Services\Discord\DiscordRecruitsClient;
use App\Services\Discord\DiscordRecruitsImporter;
use Illuminate\Console\Command;

/**
 * Pulls forum posts from the configured Discord new-recruits channel
 * into discord_recruit_forms. Each post matches the
 * "Character: NAME | Discord: NAME" title pattern; non-matching threads
 * (pinned instructional posts etc.) are skipped silently.
 *
 *   php artisan discord:fetch-recruits
 *
 * Short-circuits cleanly when the bot token / channel id env values
 * are unset.
 */
class FetchDiscordRecruits extends Command
{
    protected $signature = 'discord:fetch-recruits {--archived-pages=5 : How many pages of archived threads to walk (100 per page)}';

    protected $description = 'Pull recruit forum posts from the Discord new-recruits channel';

    public function handle(): int
    {
        $client = DiscordRecruitsClient::fromConfig();
        if (! $client->isConfigured()) {
            $this->info('discord:fetch-recruits skipped (DISCORD_BOT_TOKEN / DISCORD_RECRUITS_CHANNEL_ID not set).');
            return self::SUCCESS;
        }

        $pages = max(1, (int) $this->option('archived-pages'));

        try {
            $result = (new DiscordRecruitsImporter($client))->pull($pages);
        } catch (\Throwable $e) {
            $this->error('discord recruits pull failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info(sprintf(
            '%d recruit threads from Discord: %d imported, %d skipped (archived pages walked: %d).',
            $result['total_seen'],
            $result['imported'],
            $result['skipped'],
            $result['archived_pages'],
        ));

        return self::SUCCESS;
    }
}
