<?php

namespace App\Console\Commands;

use App\Models\DiscordWebhook;
use App\Services\Digest\WeeklyDigestBuilder;
use App\Services\Discord\DiscordWebhookPoster;
use App\Services\Discord\WebhookRouter;
use Illuminate\Console\Command;

/**
 * Builds the weekly officer digest and posts it to every webhook
 * configured under purpose='weekly_digest' on the /admin/webhooks page.
 *
 *   php artisan digest:weekly             # post to all configured webhooks
 *   php artisan digest:weekly --dry-run   # print to stdout, no post
 *
 * Designed to run via the scheduler once per week (Sun 09:00 UK by
 * default). When no webhook is configured the command prints to stdout
 * rather than failing - useful for staging or pre-config deploys.
 *
 * Backwards-compat: if the legacy DIGEST_DISCORD_WEBHOOK_URL env var
 * is set and the webhooks table is empty, we still post to it. This
 * makes the migration path painless; remove the env var once the
 * webhook is added via the admin page.
 */
class SendWeeklyDigest extends Command
{
    protected $signature = 'digest:weekly {--dry-run : Print to stdout instead of posting}';

    protected $description = 'Build and post the weekly Regenesis digest to every configured Discord webhook';

    public function handle(WebhookRouter $router): int
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

        $hooks = $router->routeFor(DiscordWebhook::PURPOSE_WEEKLY_DIGEST);

        // Legacy path: if the webhooks table has nothing for this
        // purpose but the env var still holds a URL, send to that.
        if ($hooks->isEmpty()) {
            $legacyUrl = (string) config('digest.discord_webhook_url', '');
            if ($legacyUrl !== '') {
                $r = (new DiscordWebhookPoster($legacyUrl))->post($markdown);
                if ($r['error']) {
                    $this->error("Legacy webhook post failed: {$r['error']}");
                    return self::FAILURE;
                }
                $this->info("Posted to legacy DIGEST_DISCORD_WEBHOOK_URL env var ({$r['posted']} chunk(s)). Add it to /admin/webhooks to retire the env var.");
                return self::SUCCESS;
            }
            $this->warn('No weekly_digest webhook configured; printing to stdout instead.');
            $this->line($markdown);
            return self::SUCCESS;
        }

        $totalChunks = 0;
        $failed = 0;
        foreach ($hooks as $hook) {
            $r = (new DiscordWebhookPoster($hook->url))->post($markdown);
            if ($r['error']) {
                $failed++;
                $this->error("[{$hook->label}] post failed after {$r['posted']} chunk(s): {$r['error']}");
                continue;
            }
            $totalChunks += $r['posted'];
            $hook->forceFill(['last_posted_at' => now()])->save();
        }

        if ($failed === $hooks->count()) {
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Posted weekly digest to %d webhook(s) in %d chunk(s) total (%d bytes per copy).',
            $hooks->count() - $failed, $totalChunks, $bytes,
        ));
        return self::SUCCESS;
    }
}
