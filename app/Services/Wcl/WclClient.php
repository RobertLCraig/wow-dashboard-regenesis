<?php

namespace App\Services\Wcl;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around Warcraft Logs v2 GraphQL.
 *
 * Auth: client_credentials grant. Token is cached in Cache for the
 * full lifetime WCL hands us back (typically a year for the
 * client-credentials grant), minus a small safety margin. We do not
 * need per-user OAuth - all the data we read is publicly visible
 * guild content.
 */
class WclClient
{
    private const CACHE_KEY = 'wcl.access_token';

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $tokenUrl,
        private readonly string $graphqlUrl,
        private readonly int $timeoutSeconds = 15,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            clientId: (string) config('wcl.client_id', ''),
            clientSecret: (string) config('wcl.client_secret', ''),
            tokenUrl: (string) config('wcl.token_url'),
            graphqlUrl: (string) config('wcl.graphql_url'),
            timeoutSeconds: (int) config('wcl.timeout', 15),
        );
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    /**
     * Run a GraphQL query and return the raw Http Response so callers
     * can branch on status. Body is JSON: `{query, variables}`.
     *
     * @param  array<string,mixed>  $variables
     */
    public function query(string $query, array $variables = []): Response
    {
        $token = $this->accessToken();

        return Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeoutSeconds)
            ->post($this->graphqlUrl, [
                'query' => $query,
                'variables' => (object) $variables,  // cast so empty stays as `{}` not `[]`
            ]);
    }

    /**
     * Fetch (and cache) the OAuth access token. Throws on misconfig or
     * a token endpoint failure - the calling importer surfaces the
     * exception in the sync status panel rather than silently retrying.
     */
    public function accessToken(): string
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('WCL_CLIENT_ID / WCL_CLIENT_SECRET not configured.');
        }

        $cached = Cache::get(self::CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $resp = Http::asForm()
            ->timeout($this->timeoutSeconds)
            ->withBasicAuth($this->clientId, $this->clientSecret)
            ->post($this->tokenUrl, ['grant_type' => 'client_credentials']);

        if (! $resp->successful()) {
            throw new \RuntimeException(sprintf(
                'WCL token endpoint returned %d: %s',
                $resp->status(),
                mb_substr($resp->body(), 0, 200),
            ));
        }

        $token = $resp->json('access_token');
        $expiresIn = (int) ($resp->json('expires_in') ?? 0);

        if (! is_string($token) || $token === '') {
            throw new \RuntimeException('WCL token endpoint succeeded but returned no access_token.');
        }

        // Cache for the lifetime WCL gave us, minus a 60s safety margin.
        // Falls back to 1 hour if `expires_in` is missing or absurd.
        $ttl = $expiresIn > 60 ? $expiresIn - 60 : 3600;
        Cache::put(self::CACHE_KEY, $token, $ttl);

        return $token;
    }

    /**
     * Force a re-fetch on the next call. Useful when the importer
     * sees a 401 and wants to retry with a fresh token.
     */
    public function flushTokenCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
