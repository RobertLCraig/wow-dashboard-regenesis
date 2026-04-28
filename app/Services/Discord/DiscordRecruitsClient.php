<?php

namespace App\Services\Discord;

use Illuminate\Support\Facades\Http;

/**
 * Bot-authenticated Discord client for reading the new-recruits FORUM
 * channel. Different shape to the announcements client because forums
 * use the threads endpoints, not /messages: each forum post is a
 * thread, and the post body is the first message of that thread.
 *
 * Reuses the same bot token; needs `read_messages` +
 * `read_message_history` on the recruits channel. Empty
 * recruits_channel_id => isConfigured() is false and the importer
 * no-ops cleanly.
 */
class DiscordRecruitsClient
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
            channelId: (string) config('discord.recruits_channel_id', ''),
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
     * Active (non-archived) threads in the forum. Discord returns these
     * in a single response keyed by `threads`; no pagination.
     *
     * @return list<array<string,mixed>>
     */
    public function activeThreads(): array
    {
        $this->assertConfigured();

        $response = Http::acceptJson()
            ->withHeaders(['Authorization' => 'Bot ' . $this->botToken])
            ->timeout($this->timeoutSeconds)
            ->get(self::API_BASE . "/guilds/" . config('discord.guild_id') . "/threads/active");

        $this->assertOk($response, 'active threads fetch');

        $body = $response->json();
        $threads = is_array($body) && isset($body['threads']) && is_array($body['threads'])
            ? $body['threads']
            : [];

        // /guilds/{id}/threads/active returns ALL active threads in the
        // guild; filter to our channel.
        return array_values(array_filter(
            $threads,
            fn ($t) => is_array($t)
                && isset($t['parent_id'])
                && (string) $t['parent_id'] === $this->channelId,
        ));
    }

    /**
     * Archived public threads in the forum, paginated. Discord returns
     * up to 100 per page with a `before` cursor (ISO timestamp). We
     * walk forward until either has_more is false or we hit the
     * caller's page cap, whichever comes first.
     *
     * @return list<array<string,mixed>>
     */
    public function archivedThreads(int $maxPages = 5): array
    {
        $this->assertConfigured();

        $all = [];
        $before = null;
        for ($page = 0; $page < $maxPages; $page++) {
            $query = ['limit' => 100];
            if ($before !== null) {
                $query['before'] = $before;
            }

            $response = Http::acceptJson()
                ->withHeaders(['Authorization' => 'Bot ' . $this->botToken])
                ->timeout($this->timeoutSeconds)
                ->get(self::API_BASE . "/channels/{$this->channelId}/threads/archived/public", $query);

            $this->assertOk($response, 'archived threads fetch');

            $body = $response->json();
            if (! is_array($body)) {
                break;
            }
            $threads = isset($body['threads']) && is_array($body['threads']) ? $body['threads'] : [];
            foreach ($threads as $t) {
                if (is_array($t)) {
                    $all[] = $t;
                }
            }
            if (empty($threads) || empty($body['has_more'])) {
                break;
            }
            // Cursor for next page = archive timestamp of the oldest
            // thread we just received.
            $last = end($threads);
            $before = is_array($last) && isset($last['thread_metadata']['archive_timestamp'])
                ? (string) $last['thread_metadata']['archive_timestamp']
                : null;
            if ($before === null) {
                break;
            }
        }

        return $all;
    }

    /**
     * Forum-post body = first message in the thread. Discord guarantees
     * the first message has the same id as the thread itself, so we
     * fetch it directly.
     *
     * @return array<string,mixed>|null
     */
    public function firstMessage(string $threadId): ?array
    {
        $this->assertConfigured();

        $response = Http::acceptJson()
            ->withHeaders(['Authorization' => 'Bot ' . $this->botToken])
            ->timeout($this->timeoutSeconds)
            ->get(self::API_BASE . "/channels/{$threadId}/messages/{$threadId}");

        // 404 is normal when the bot lacks history scope on a single
        // archived thread or the original message was deleted; treat as
        // "no body" rather than fatal.
        if ($response->status() === 404) {
            return null;
        }
        $this->assertOk($response, 'first-message fetch');

        $body = $response->json();
        return is_array($body) ? $body : null;
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException(
                'Discord bot token / recruits channel id are not configured. '
                . 'Set DISCORD_BOT_TOKEN and DISCORD_RECRUITS_CHANNEL_ID.'
            );
        }
    }

    private function assertOk(\Illuminate\Http\Client\Response $response, string $context): void
    {
        if (! $response->successful()) {
            throw new \RuntimeException(sprintf(
                'Discord recruits %s failed: %d %s',
                $context,
                $response->status(),
                mb_substr((string) $response->body(), 0, 200),
            ));
        }
    }
}
