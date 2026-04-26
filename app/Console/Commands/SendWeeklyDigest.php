<?php

namespace App\Console\Commands;

use App\Services\Digest\WeeklyDigestBuilder;
use App\Services\Discord\DiscordWebhookPoster;
use Illuminate\Console\Command;

/**
 * Builds the weekly officer digest and posts it to the configured
 * Discord webhook.
 *
 *   php artisan digest:weekly             # post to Discord
 *   php artisan digest:weekly --dry-run   # print to stdout, no post
 *
 * Designed to run via the scheduler once per week (Sun 09:00 UK by
 * default). Short-circuits cleanly when DIGEST_DISCORD_WEBHOOK_URL is
 * empty so a pre-configured deploy doesn't error before the webhook is
 * set up.
 */
class SendWeeklyDigest extends Command
{
    protected $signature = 'digest:weekly {--dry-run : Print to stdout instead of posting}';

    protected $description = 'Build and post the weekly Regenesis digest to Discord';

    public function handle(): int
    {
        $built = (new WeeklyDigestBuilder(
            guildKey: (string) config('grm.guild_key'),
        ))->build();

        $markdown = $built['markdown'];
        $bytes = strlen($markdown);

        if ($this->option('dry-run')) {
            $this->info("--- DIGEST PREVIEW ({$bytes} bytes) ---");
            $this->line($markdown);
            return self::SUCCESS;
        }

        $poster = DiscordWebhookPoster::fromConfig();
        if (! $poster->isConfigured()) {
            $this->warn('DIGEST_DISCORD_WEBHOOK_URL not set; printing to stdout instead.');
            $this->line($markdown);
            return self::SUCCESS;
        }

        $result = $poster->post($markdown);
        if ($result['error']) {
            $this->error("Digest post failed after {$result['posted']} chunk(s): {$result['error']}");
            return self::FAILURE;
        }

        $this->info("Posted weekly digest to Discord in {$result['posted']} chunk(s) ({$bytes} bytes total).");
        return self::SUCCESS;
    }
}
