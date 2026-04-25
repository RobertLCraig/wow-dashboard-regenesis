<?php

namespace App\Services\Discord;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads a user's roles within the Regenesis Discord server and decides
 * which permission tier they are (if any).
 *
 * Authoritative endpoint: GET /users/@me/guilds/{guild_id}/member
 * (requires the OAuth `guilds.members.read` scope at sign-in time).
 *
 * Result is cached per-user for `discord.role_cache_ttl_minutes` so we
 * don't hammer Discord's rate limits, and the User row's tier column is
 * updated as a side effect on every fresh fetch so middleware can
 * fast-path off the column when desired.
 */
class RoleVerifier
{
    public const NO_TIER = null;

    public function __construct(
        private readonly string $guildId,
        /** @var array<string,?string>  e.g. ['gm' => '12345', 'big6' => '67890', 'officer' => '...'] */
        private readonly array $tierRoleIds,
        private readonly int $cacheTtlMinutes = 5,
    ) {}

    /**
     * Return the user's tier ('gm'|'big6'|'officer') or null if they
     * hold none of the configured roles.
     */
    public function tierFor(User $user, bool $force = false): ?string
    {
        $cacheKey = "discord.tier.user.{$user->id}";
        if (! $force && ($cached = Cache::get($cacheKey)) !== null) {
            return $cached === '' ? null : $cached;
        }

        $accessToken = $this->refreshedAccessToken($user);
        if ($accessToken === null) {
            // No refresh token or refresh failed; treat as no access. The
            // caller will redirect back to OAuth on the next request.
            Cache::put($cacheKey, '', now()->addMinutes($this->cacheTtlMinutes));
            return null;
        }

        try {
            $resp = Http::withToken($accessToken)
                ->acceptJson()
                ->timeout(5)
                ->get("https://discord.com/api/v10/users/@me/guilds/{$this->guildId}/member");
        } catch (ConnectionException $e) {
            Log::warning('Discord role check connection failed', ['user_id' => $user->id, 'message' => $e->getMessage()]);
            // Don't cache a transient network failure as "no tier" -
            // returns the last known tier from the User row instead.
            return $user->tier;
        }

        if ($resp->status() === 404) {
            // User isn't in the configured guild at all.
            Cache::put($cacheKey, '', now()->addMinutes($this->cacheTtlMinutes));
            $user->forceFill(['tier' => null, 'last_role_check_at' => now()])->save();
            return null;
        }

        if (! $resp->successful()) {
            Log::warning('Discord role check non-2xx', [
                'user_id' => $user->id,
                'status' => $resp->status(),
                'body' => mb_substr($resp->body(), 0, 200),
            ]);
            return $user->tier;
        }

        $roles = $resp->json('roles', []);
        $tier = $this->tierFromRoles(is_array($roles) ? $roles : []);

        Cache::put($cacheKey, $tier ?? '', now()->addMinutes($this->cacheTtlMinutes));
        $user->forceFill([
            'tier' => $tier,
            'last_role_check_at' => now(),
        ])->save();

        return $tier;
    }

    /**
     * Highest-precedence tier wins (gm > big6 > officer).
     *
     * @param  array<int,string>  $roleIds
     */
    public function tierFromRoles(array $roleIds): ?string
    {
        $set = array_flip(array_map('strval', $roleIds));
        foreach (['gm', 'big6', 'officer'] as $tier) {
            $needed = $this->tierRoleIds[$tier] ?? null;
            if ($needed && isset($set[(string) $needed])) {
                return $tier;
            }
        }
        return null;
    }

    /**
     * Use the stored refresh token to obtain a fresh access token. We do
     * NOT persist access tokens (they're short-lived); each role check
     * trades the long-lived refresh token for a one-shot access token.
     */
    private function refreshedAccessToken(User $user): ?string
    {
        $refresh = $user->discord_refresh_token;
        if (! $refresh) {
            return null;
        }
        try {
            $resp = Http::asForm()
                ->timeout(5)
                ->post('https://discord.com/api/v10/oauth2/token', [
                    'client_id' => config('services.discord.client_id'),
                    'client_secret' => config('services.discord.client_secret'),
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refresh,
                ])
                ->throw();
        } catch (ConnectionException|RequestException $e) {
            Log::warning('Discord token refresh failed', ['user_id' => $user->id, 'message' => $e->getMessage()]);
            return null;
        }

        $body = $resp->json();

        // Persist the new refresh token (Discord rotates it on each
        // refresh) so the next role-check uses the latest.
        if (! empty($body['refresh_token'])) {
            $user->discord_refresh_token = $body['refresh_token'];
            $user->save();
        }

        return $body['access_token'] ?? null;
    }

    public static function fromConfig(): self
    {
        return new self(
            guildId: (string) config('discord.guild_id'),
            tierRoleIds: (array) config('discord.roles'),
            cacheTtlMinutes: (int) config('discord.role_cache_ttl_minutes', 5),
        );
    }
}
