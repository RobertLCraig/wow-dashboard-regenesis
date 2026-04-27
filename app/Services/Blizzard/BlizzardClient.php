<?php

namespace App\Services\Blizzard;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around Blizzard's WoW Profile API. Unlike Raider.IO this
 * one needs OAuth: a client_credentials token (good for ~24h) is fetched
 * on first use and cached, then attached as a bearer to every profile
 * request along with the region-specific `Battlenet-Namespace` header.
 *
 * Endpoint inventory:
 *   POST  https://oauth.battle.net/token
 *     grant_type=client_credentials, HTTP Basic id:secret. Returns
 *     { access_token, expires_in, ... }.
 *   GET   /profile/wow/character/{realm-slug}/{character-name-lower}
 *     Returns the character's profile summary (level, faction, average +
 *     equipped item level, last_login_timestamp, active_spec, etc).
 *     404 for unknown / never-logged-in chars.
 */
class BlizzardClient
{
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $apiBaseUrl,
        private readonly string $oauthTokenUrl,
        private readonly string $namespace,
        private readonly string $locale = 'en_GB',
        private readonly int $timeoutSeconds = 10,
        private readonly int $tokenCacheTtlSeconds = 82800,
    ) {}

    public static function fromConfig(): self
    {
        $region = strtolower((string) config('blizzard.region', 'eu'));
        $apiBase = (string) (config('blizzard.api_base_url') ?: "https://{$region}.api.blizzard.com");
        $namespace = (string) (config('blizzard.namespace') ?: "profile-{$region}");

        return new self(
            clientId: (string) config('blizzard.client_id', ''),
            clientSecret: (string) config('blizzard.client_secret', ''),
            apiBaseUrl: rtrim($apiBase, '/'),
            oauthTokenUrl: (string) config('blizzard.oauth_token_url', 'https://oauth.battle.net/token'),
            namespace: $namespace,
            locale: (string) config('blizzard.locale', 'en_GB'),
            timeoutSeconds: (int) config('blizzard.timeout', 10),
            tokenCacheTtlSeconds: (int) config('blizzard.token_cache_ttl', 82800),
        );
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    /**
     * Fetch a single character profile. Caller branches on status -
     * 404 is the normal "unknown char" outcome and not an error.
     */
    public function profile(string $realmSlug, string $name): Response
    {
        ['url' => $url, 'headers' => $headers, 'query' => $query] = $this->profileEndpoint($realmSlug, $name);
        return Http::acceptJson()
            ->timeout($this->timeoutSeconds)
            ->withHeaders($headers)
            ->get($url, $query);
    }

    /**
     * Pre-computed url + headers + query for one character. The importer
     * uses this to dispatch many fetches through Http::pool() without
     * reaching into client internals.
     *
     * @return array{url: string, headers: array<string, string>, query: array<string, string>}
     */
    public function profileEndpoint(string $realmSlug, string $name): array
    {
        return [
            'url' => sprintf(
                '%s/profile/wow/character/%s/%s',
                $this->apiBaseUrl,
                rawurlencode($realmSlug),
                rawurlencode(mb_strtolower($name)),
            ),
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken(),
                'Battlenet-Namespace' => $this->namespace,
            ],
            'query' => [
                'locale' => $this->locale,
            ],
        ];
    }

    /** Forget the cached token, e.g. after a 401 from a profile call. */
    public function forgetToken(): void
    {
        Cache::forget($this->cacheKey());
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    private function accessToken(): string
    {
        return Cache::remember(
            $this->cacheKey(),
            $this->tokenCacheTtlSeconds,
            fn (): string => $this->fetchAccessToken(),
        );
    }

    private function fetchAccessToken(): string
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException(
                'Battle.net client credentials are not configured. '
                . 'Set BLIZZARD_CLIENT_ID and BLIZZARD_CLIENT_SECRET.'
            );
        }

        $response = Http::timeout($this->timeoutSeconds)
            ->asForm()
            ->withBasicAuth($this->clientId, $this->clientSecret)
            ->post($this->oauthTokenUrl, ['grant_type' => 'client_credentials']);

        if (! $response->successful()) {
            throw new \RuntimeException(sprintf(
                'Battle.net OAuth failed: %d %s',
                $response->status(),
                mb_substr((string) $response->body(), 0, 200),
            ));
        }

        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new \RuntimeException('Battle.net OAuth returned no access_token');
        }
        return $token;
    }

    /**
     * Cache key varies by client id + token URL so different envs and
     * regions don't collide. SHA-1 keeps the key opaque (the client id
     * isn't a secret, but we'd rather not see it in cache dumps).
     */
    private function cacheKey(): string
    {
        return 'blizzard.access_token.' . sha1($this->clientId . '|' . $this->oauthTokenUrl);
    }
}
