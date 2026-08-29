<?php

namespace App\Services\Discord;

use App\Models\DiscordAnnouncement;
use Carbon\CarbonImmutable;

/**
 * Pulls the recent message page from the announcements channel and
 * upserts each message onto discord_announcements (keyed on the
 * Discord snowflake so re-runs are idempotent). A message is only
 * skipped when it has neither text nor attachments - an image-only
 * poster or roster screenshot is exactly what the feed wants.
 *
 * Attachment urls are stored as a cache, not an address: Discord signs
 * CDN links with an expiry, and it is the hourly re-pull that keeps
 * them fresh. The attachment id and the message id are stored beside
 * them so a url can always be re-fetched from Discord if needed.
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

            $content = is_string($content) ? $content : '';
            $attachments = $this->attachments($msg);

            if (! is_string($messageId) || $messageId === '') {
                $skipped++;

                continue;
            }
            if (trim($content) === '' && $attachments === []) {
                // Nothing to show: no text and nothing to render.
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
                    'attachments' => $attachments !== [] ? $attachments : null,
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

    /**
     * Narrow a Discord message's attachments to the fields the feed
     * needs. `id` + the message id are what a re-fetch would key on;
     * `url` / `proxy_url` are signed and expire, so they are refreshed
     * by every pull rather than trusted forever.
     *
     * ponytail: relies on the hourly pull keeping the shown posts' urls
     * fresh, which holds while the channel stays under the 50-message
     * pull limit per day. Re-fetch a message by id on render if a busier
     * channel ever pushes a still-displayed post out of that window.
     *
     * @param  array<string,mixed>  $msg
     * @return list<array<string,mixed>>
     */
    private function attachments(array $msg): array
    {
        $raw = $msg['attachments'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $kept = [];
        foreach ($raw as $attachment) {
            if (! is_array($attachment) || ! isset($attachment['url']) || ! is_string($attachment['url'])) {
                continue;
            }

            $kept[] = [
                'id' => isset($attachment['id']) ? (string) $attachment['id'] : null,
                'filename' => isset($attachment['filename']) ? (string) $attachment['filename'] : null,
                'content_type' => isset($attachment['content_type']) ? (string) $attachment['content_type'] : null,
                'size' => isset($attachment['size']) ? (int) $attachment['size'] : null,
                'width' => isset($attachment['width']) ? (int) $attachment['width'] : null,
                'height' => isset($attachment['height']) ? (int) $attachment['height'] : null,
                'url' => $attachment['url'],
                'proxy_url' => isset($attachment['proxy_url']) && is_string($attachment['proxy_url'])
                    ? $attachment['proxy_url'] : null,
            ];
        }

        return $kept;
    }
}
