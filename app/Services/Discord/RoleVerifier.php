<?php

namespace App\Services\Discord;

use App\Models\User;
use App\Services\Teams\TeamResolver;
use Illuminate\Http\Client\ConnectionException;
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

        try {
            $accessToken = $this->refreshedAccessToken($user);
        } catch (TransientDiscordFailure $e) {
            // Discord blipped (5xx / 429 / timeout). Don't lock the user
            // out for the full TTL; cache the last known tier briefly so
            // a flood of requests doesn't keep retrying, and try again
            // soon.
            Cache::put($cacheKey, $user->tier ?? '', now()->addSeconds(30));
            return $user->tier;
        }

        if ($accessToken === null) {
            // Refresh token is genuinely rejected (or absent). User
            // needs to re-OAuth; cache the deny for the full TTL.
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
            Cache::put($cacheKey, $user->tier ?? '', now()->addSeconds(30));
            return $user->tier;
        }

        if ($resp->status() === 404) {
            // User isn't in the configured guild at all.
            Cache::put($cacheKey, '', now()->addMinutes($this->cacheTtlMinutes));
            $user->forceFill(['tier' => null, 'last_role_check_at' => now()])->save();
            return null;
        }

        if ($resp->status() >= 500 || $resp->status() === 429) {
            Log::warning('Discord role check transient', ['user_id' => $user->id, 'status' => $resp->status()]);
            Cache::put($cacheKey, $user->tier ?? '', now()->addSeconds(30));
            return $user->tier;
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
        $rolesArr = is_array($roles) ? $roles : [];
        $tier = $this->tierFromRoles($rolesArr);
        $team = app(TeamResolver::class)->forRoleIds($rolesArr);

        Cache::put($cacheKey, $tier ?? '', now()->addMinutes($this->cacheTtlMinutes));
        $user->forceFill([
            'tier' => $tier,
            'team' => $team,
            'last_role_check_at' => now(),
        ])->save();

        return $tier;
    }

    /**
     * Highest-precedence tier wins (gm > big6 > officer > raid_leader).
     *
     * @param  array<int,string>  $roleIds
     */
    public function tierFromRoles(array $roleIds): ?string
    {
        $set = array_flip(array_map('strval', $roleIds));
        foreach (['gm', 'big6', 'officer', 'raid_leader'] as $tier) {
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
     *
     * Returns null only when Discord actively rejects the refresh token
     * (the user genuinely needs to re-OAuth). Throws TransientDiscordFailure
     * on 5xx / 429 / network errors so the caller can fall back to the
     * last known good tier instead of locking the user out.
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
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Discord token refresh connection failed', ['user_id' => $user->id, 'message' => $e->getMessage()]);
            throw new TransientDiscordFailure('connection failed', previous: $e);
        }

        if ($resp->status() >= 500 || $resp->status() === 429) {
            Log::warning('Discord token refresh transient', ['user_id' => $user->id, 'status' => $resp->status()]);
            throw new TransientDiscordFailure('discord status '.$resp->status());
        }

        if (! $resp->successful()) {
            // 4xx (typically 400 invalid_grant): refresh token is bad.
            Log::warning('Discord token refresh rejected', [
                'user_id' => $user->id,
                'status' => $resp->status(),
                'body' => mb_substr($resp->body(), 0, 200),
            ]);
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
