<?php

namespace App\Services\Blizzard;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around Blizzard's WoW APIs. OAuth is client_credentials
 * (good for ~24h, fetched on first use and cached) and attached as a
 * bearer to every request along with the appropriate `Battlenet-Namespace`
 * header. Battle.net partitions data by namespace:
 *
 *   profile-{region}   character profile + sub-resources
 *                      (/profile/wow/character/...)
 *   dynamic-{region}   guild data + frequently-changing game data
 *                      (/data/wow/guild/..., /data/wow/mythic-keystone/...)
 *   static-{region}    rarely-changing reference data (item details,
 *                      icons, journal/encounter info)
 *
 * Endpoint inventory:
 *   POST  https://oauth.battle.net/token
 *     grant_type=client_credentials, HTTP Basic id:secret. Returns
 *     { access_token, expires_in, ... }.
 *   GET   /profile/wow/character/{realm-slug}/{character-name-lower}
 *     Profile summary (level, faction, equipped/average ilvl,
 *     last_login_timestamp, active spec). 404 = unknown character.
 *   GET   /profile/wow/character/{realm-slug}/{character-name-lower}/status
 *     { id, is_valid }. Cheap canary for "this character still exists".
 *   GET   /profile/wow/character/{realm-slug}/{character-name-lower}/equipment
 *     Per-piece gear with enchants, gems, sockets, bonus IDs.
 *   GET   /data/wow/guild/{realm-slug}/{name-slug}/roster
 *     Authoritative guild roster: name, realm, level, race, class,
 *     rank index, character ID. No notes, no join dates - GRM still
 *     owns those.
 */
class BlizzardClient
{
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $apiBaseUrl,
        private readonly string $oauthTokenUrl,
        private readonly string $namespace,
        private readonly string $dynamicNamespace,
        private readonly string $locale = 'en_GB',
        private readonly int $timeoutSeconds = 10,
        private readonly int $tokenCacheTtlSeconds = 82800,
    ) {}

    public static function fromConfig(): self
    {
        $region = strtolower((string) config('blizzard.region', 'eu'));
        $apiBase = (string) (config('blizzard.api_base_url') ?: "https://{$region}.api.blizzard.com");
        $namespace = (string) (config('blizzard.namespace') ?: "profile-{$region}");
        $dynamicNamespace = (string) (config('blizzard.dynamic_namespace') ?: "dynamic-{$region}");

        return new self(
            clientId: (string) config('blizzard.client_id', ''),
            clientSecret: (string) config('blizzard.client_secret', ''),
            apiBaseUrl: rtrim($apiBase, '/'),
            oauthTokenUrl: (string) config('blizzard.oauth_token_url', 'https://oauth.battle.net/token'),
            namespace: $namespace,
            dynamicNamespace: $dynamicNamespace,
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
     * Pre-computed url + headers + query for one character profile.
     * The importer uses this to dispatch many fetches through Http::pool()
     * without reaching into client internals.
     *
     * @return array{url: string, headers: array<string, string>, query: array<string, string>}
     */
    public function profileEndpoint(string $realmSlug, string $name): array
    {
        return [
            'url' => $this->characterUrl($realmSlug, $name),
            'headers' => $this->profileHeaders(),
            'query' => ['locale' => $this->locale],
        ];
    }

    /**
     * Status sub-resource. Returns { id, is_valid } - tiny payload, ideal
     * for a periodic canary to flag deleted/transferred characters
     * without re-pulling the full profile blob.
     */
    public function status(string $realmSlug, string $name): Response
    {
        ['url' => $url, 'headers' => $headers, 'query' => $query] = $this->statusEndpoint($realmSlug, $name);
        return Http::acceptJson()
            ->timeout($this->timeoutSeconds)
            ->withHeaders($headers)
            ->get($url, $query);
    }

    /**
     * @return array{url: string, headers: array<string, string>, query: array<string, string>}
     */
    public function statusEndpoint(string $realmSlug, string $name): array
    {
        return [
            'url' => $this->characterUrl($realmSlug, $name) . '/status',
            'headers' => $this->profileHeaders(),
            'query' => ['locale' => $this->locale],
        ];
    }

    /**
     * Equipment sub-resource. Returns the equipped_items array with one
     * entry per slot, each carrying item id, slot, enchantments, sockets,
     * bonus list, item level, and inventory_type. The pre-raid readiness
     * checks read this for "missing enchant / empty socket" signals.
     */
    public function equipment(string $realmSlug, string $name): Response
    {
        ['url' => $url, 'headers' => $headers, 'query' => $query] = $this->equipmentEndpoint($realmSlug, $name);
        return Http::acceptJson()
            ->timeout($this->timeoutSeconds)
            ->withHeaders($headers)
            ->get($url, $query);
    }

    /**
     * @return array{url: string, headers: array<string, string>, query: array<string, string>}
     */
    public function equipmentEndpoint(string $realmSlug, string $name): array
    {
        return [
            'url' => $this->characterUrl($realmSlug, $name) . '/equipment',
            'headers' => $this->profileHeaders(),
            'query' => ['locale' => $this->locale],
        ];
    }

    /**
     * Character media. Tiny payload with avatar / inset / main /
     * main-raw render URLs. Used for social pages, transmog review,
     * roster portraits.
     *
     * @return array{url: string, headers: array<string, string>, query: array<string, string>}
     */
    public function characterMediaEndpoint(string $realmSlug, string $name): array
    {
        return [
            'url' => $this->characterUrl($realmSlug, $name) . '/character-media',
            'headers' => $this->profileHeaders(),
            'query' => ['locale' => $this->locale],
        ];
    }

    /**
     * Achievements: full list of completed achievements with
     * timestamps. Drives AOTC/CE/Keystone Hero detection without
     * member self-reporting.
     *
     * @return array{url: string, headers: array<string, string>, query: array<string, string>}
     */
    public function achievementsEndpoint(string $realmSlug, string $name): array
    {
        return [
            'url' => $this->characterUrl($realmSlug, $name) . '/achievements',
            'headers' => $this->profileHeaders(),
            'query' => ['locale' => $this->locale],
        ];
    }

    /**
     * Collection sub-resource (mounts | pets | toys | transmogs).
     * Each call returns just one collection type's payload.
     *
     * @return array{url: string, headers: array<string, string>, query: array<string, string>}
     */
    public function collectionsEndpoint(string $realmSlug, string $name, string $collection): array
    {
        return [
            'url' => $this->characterUrl($realmSlug, $name) . '/collections/' . rawurlencode($collection),
            'headers' => $this->profileHeaders(),
            'query' => ['locale' => $this->locale],
        ];
    }

    /**
     * Encounters > raids. Returns expansions[] -> instances[] ->
     * modes[] (per difficulty) -> progress with per-encounter
     * completed_count + last_kill_timestamp. No opt-in needed (unlike
     * wowaudit's progression view); covers any guild member.
     */
    public function raidEncounters(string $realmSlug, string $name): Response
    {
        ['url' => $url, 'headers' => $headers, 'query' => $query] = $this->raidEncountersEndpoint($realmSlug, $name);
        return Http::acceptJson()
            ->timeout($this->timeoutSeconds)
            ->withHeaders($headers)
            ->get($url, $query);
    }

    /**
     * @return array{url: string, headers: array<string, string>, query: array<string, string>}
     */
    public function raidEncountersEndpoint(string $realmSlug, string $name): array
    {
        return [
            'url' => $this->characterUrl($realmSlug, $name) . '/encounters/raids',
            'headers' => $this->profileHeaders(),
            'query' => ['locale' => $this->locale],
        ];
    }

    /**
     * Mythic keystone profile. Returns current_period (best_runs +
     * mythic_rating), seasons (per-season summary), and the rolled-up
     * current_mythic_rating. Stored alongside RIO's data so we have
     * Blizzard's authoritative numbers without dropping RIO.
     */
    public function mythicKeystoneProfile(string $realmSlug, string $name): Response
    {
        ['url' => $url, 'headers' => $headers, 'query' => $query] = $this->mythicKeystoneProfileEndpoint($realmSlug, $name);
        return Http::acceptJson()
            ->timeout($this->timeoutSeconds)
            ->withHeaders($headers)
            ->get($url, $query);
    }

    /**
     * @return array{url: string, headers: array<string, string>, query: array<string, string>}
     */
    public function mythicKeystoneProfileEndpoint(string $realmSlug, string $name): array
    {
        return [
            'url' => $this->characterUrl($realmSlug, $name) . '/mythic-keystone-profile',
            'headers' => $this->profileHeaders(),
            'query' => ['locale' => $this->locale],
        ];
    }

    /**
     * Guild roster. Uses dynamic-{region} (not profile-{region}). The
     * payload is { guild: {...}, members: [{ character: { name, id,
     * realm: { slug, name } }, rank: int }, ...] }. One call covers
     * every member, no fan-out needed.
     */
    public function guildRoster(string $realmSlug, string $guildNameSlug): Response
    {
        return Http::acceptJson()
            ->timeout($this->timeoutSeconds)
            ->withHeaders($this->dynamicHeaders())
            ->get(
                sprintf(
                    '%s/data/wow/guild/%s/%s/roster',
                    $this->apiBaseUrl,
                    rawurlencode($realmSlug),
                    rawurlencode($guildNameSlug),
                ),
                ['locale' => $this->locale],
            );
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

    private function characterUrl(string $realmSlug, string $name): string
    {
        return sprintf(
            '%s/profile/wow/character/%s/%s',
            $this->apiBaseUrl,
            rawurlencode($realmSlug),
            rawurlencode(mb_strtolower($name)),
        );
    }

    /** @return array<string, string> */
    private function profileHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->accessToken(),
            'Battlenet-Namespace' => $this->namespace,
        ];
    }

    /** @return array<string, string> */
    private function dynamicHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->accessToken(),
            'Battlenet-Namespace' => $this->dynamicNamespace,
        ];
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
