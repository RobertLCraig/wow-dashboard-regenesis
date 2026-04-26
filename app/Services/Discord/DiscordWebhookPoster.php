<?php

namespace App\Services\Discord;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Posts a markdown payload to a Discord channel webhook URL.
 *
 * Discord caps a single message at 2000 characters. Anything longer is
 * split on the nearest preceding newline (or hard-cut at 2000 if a
 * single line is too long). Chunks post sequentially with a tiny pause
 * between calls to stay polite about Discord's per-webhook rate limit
 * (5 requests / 2s, generally).
 */
class DiscordWebhookPoster
{
    private const MESSAGE_LIMIT = 2000;
    private const INTER_CHUNK_DELAY_MS = 250;

    public function __construct(
        private readonly string $webhookUrl,
        private readonly int $timeoutSeconds = 10,
    ) {}

    public function isConfigured(): bool
    {
        return $this->webhookUrl !== '';
    }

    /**
     * @return array{posted: int, status: int|null, error: ?string}
     */
    public function post(string $markdown): array
    {
        if (! $this->isConfigured()) {
            return ['posted' => 0, 'status' => null, 'error' => 'webhook URL not configured'];
        }

        $chunks = self::chunk($markdown, self::MESSAGE_LIMIT);
        $posted = 0;
        $lastStatus = null;

        foreach ($chunks as $i => $chunk) {
            try {
                $resp = Http::timeout($this->timeoutSeconds)
                    ->asJson()
                    ->post($this->webhookUrl, ['content' => $chunk]);
            } catch (ConnectionException|RequestException $e) {
                return [
                    'posted' => $posted,
                    'status' => $lastStatus,
                    'error' => 'request failed: ' . $e->getMessage(),
                ];
            }

            $lastStatus = $resp->status();
            if (! $resp->successful()) {
                return [
                    'posted' => $posted,
                    'status' => $resp->status(),
                    'error' => "discord returned {$resp->status()}: " . mb_substr($resp->body(), 0, 200),
                ];
            }
            $posted++;

            if ($i < count($chunks) - 1 && self::INTER_CHUNK_DELAY_MS > 0) {
                usleep(self::INTER_CHUNK_DELAY_MS * 1000);
            }
        }

        return ['posted' => $posted, 'status' => $lastStatus, 'error' => null];
    }

    /**
     * Public so the digest preview / tests can see how a body would be
     * carved up without actually hitting Discord.
     *
     * @return list<string>
     */
    public static function chunk(string $body, int $limit): array
    {
        if (strlen($body) <= $limit) {
            return [$body];
        }

        $chunks = [];
        $remaining = $body;

        while (strlen($remaining) > $limit) {
            // Look for the last newline within the budget so we don't
            // chop a sentence mid-word. Fall back to a hard cut if a
            // single line is somehow longer than the limit.
            $candidate = substr($remaining, 0, $limit);
            $break = strrpos($candidate, "\n");
            $cut = $break !== false && $break > 0 ? $break : $limit;
            $chunks[] = rtrim(substr($remaining, 0, $cut));
            $remaining = ltrim(substr($remaining, $cut));
        }
        if ($remaining !== '') {
            $chunks[] = $remaining;
        }

        return $chunks;
    }

    public static function fromConfig(): self
    {
        return new self(
            webhookUrl: (string) config('digest.discord_webhook_url', ''),
            timeoutSeconds: (int) config('digest.timeout', 10),
        );
    }
}
