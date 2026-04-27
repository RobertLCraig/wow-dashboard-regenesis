<?php

namespace App\Services\Discord;

use App\Models\DiscordAnnouncement;
use Carbon\CarbonImmutable;

/**
 * Pulls the recent message page from the announcements channel and
 * upserts each message onto discord_announcements (keyed on the
 * Discord snowflake so re-runs are idempotent). Empty-content messages
 * - usually image-only embeds with no caption - are skipped because
 * they're not useful on the social feed without their attachments.
 *
 * Future tier could store attachments + embeds as JSON for richer
 * rendering; v1 keeps it text-first.
 */
class DiscordAnnouncementsImporter
{
    public function __construct(
        private readonly DiscordAnnouncementsClient $client,
    ) {}

    /**
     * @return array{imported:int, skipped:int, total_seen:int}
     */
    public function pull(int $limit = 50): array
    {
        if (! $this->client->isConfigured()) {
            throw new \RuntimeException(
                'Discord bot token / announcements channel id are not configured.'
            );
        }

        $messages = $this->client->recentMessages($limit);
        $now = CarbonImmutable::now();
        $guildId = (string) config('discord.guild_id', '');
        $imported = 0;
        $skipped = 0;

        foreach ($messages as $msg) {
            $messageId = $msg['id'] ?? null;
            $content = $msg['content'] ?? '';
            $author = $msg['author'] ?? null;
            $timestamp = $msg['timestamp'] ?? null;
            $channelId = $msg['channel_id'] ?? $this->client->channelId();

            if (! is_string($messageId) || $messageId === '') {
                $skipped++;
                continue;
            }
            if (! is_string($content) || trim($content) === '') {
                // Empty messages (image-only, sticker-only, system) are
                // useless without their attachments; skip until we add
                // attachment storage.
                $skipped++;
                continue;
            }

            try {
                $postedAt = is_string($timestamp) && $timestamp !== ''
                    ? CarbonImmutable::parse($timestamp)
                    : $now;
            } catch (\Throwable) {
                $postedAt = $now;
            }

            DiscordAnnouncement::query()->updateOrCreate(
                ['discord_message_id' => $messageId],
                [
                    'guild_id' => $guildId !== '' ? $guildId : null,
                    'channel_id' => $channelId,
                    'author_username' => is_array($author) && isset($author['username']) && is_string($author['username'])
                        ? $author['username'] : 'unknown',
                    'author_id' => is_array($author) && isset($author['id']) && is_string($author['id'])
                        ? $author['id'] : null,
                    'content' => $content,
                    'posted_at' => $postedAt,
                    'fetched_at' => $now,
                ]
            );
            $imported++;
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'total_seen' => count($messages),
        ];
    }
}
