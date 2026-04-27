<?php

namespace App\Services\Discord;

use Illuminate\Support\Facades\Http;

/**
 * Bot-authenticated Discord client for reading messages out of the
 * announcements channel. Uses a Bot token (NOT a user OAuth token):
 * gear up by creating a bot at <https://discord.com/developers/applications>,
 * granting `read_messages` + `read_message_history`, and inviting it to
 * the guild. Both env values blank means the importer no-ops cleanly.
 */
class DiscordAnnouncementsClient
{
    private const API_BASE = 'https://discord.com/api/v10';

    public function __construct(
        private readonly string $botToken,
        private readonly string $channelId,
        private readonly int $timeoutSeconds = 10,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            botToken: (string) config('discord.bot_token', ''),
            channelId: (string) config('discord.announcements_channel_id', ''),
            timeoutSeconds: (int) config('discord.http_timeout', 10),
        );
    }

    public function isConfigured(): bool
    {
        return $this->botToken !== '' && $this->channelId !== '';
    }

    public function channelId(): string
    {
        return $this->channelId;
    }

    /**
     * Fetch the most recent N messages from the configured channel.
     * Discord returns newest-first; the importer doesn't care about
     * order since we upsert by message id.
     *
     * @return list<array<string,mixed>>
     */
    public function recentMessages(int $limit = 50): array
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException(
                'Discord bot token / announcements channel id are not configured. '
                . 'Set DISCORD_BOT_TOKEN and DISCORD_ANNOUNCEMENTS_CHANNEL_ID.'
            );
        }
        $clamped = max(1, min(100, $limit));

        $response = Http::acceptJson()
            ->withHeaders(['Authorization' => 'Bot ' . $this->botToken])
            ->timeout($this->timeoutSeconds)
            ->get(self::API_BASE . "/channels/{$this->channelId}/messages", [
                'limit' => $clamped,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException(sprintf(
                'Discord channel messages fetch failed: %d %s',
                $response->status(),
                mb_substr((string) $response->body(), 0, 200),
            ));
        }

        $body = $response->json();
        return is_array($body) ? array_values($body) : [];
    }
}
