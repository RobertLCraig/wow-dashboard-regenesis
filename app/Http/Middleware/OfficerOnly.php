<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Discord\RoleVerifier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate every dashboard route on Discord officer-tier membership.
 *
 * Trust the cached User#tier column for routine page loads. When that
 * cache is stale (older than the configured TTL), a re-check against
 * Discord runs so a removed role takes effect within ~5 minutes
 * without forcing a re-login.
 *
 * Performance: the stale re-check is deferred to `app()->terminating()`
 * for users who already have a known tier, so the user never waits for
 * Discord on the request hot path. The fresh state lands in time for
 * the next request. Users without a cached tier still get a synchronous
 * check (otherwise a freshly-promoted user would have to wait for a
 * second page load before getting in).
 *
 * On 'no tier' (left the guild, role removed, never had it) the user
 * gets a 403 with a message telling them why - not a redirect, because
 * the OAuth handshake already succeeded; the issue is authorisation
 * rather than authentication.
 */
class OfficerOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('auth.discord.start');
        }

        $ttl = (int) config('discord.role_cache_ttl_minutes', 5);
        $stale = $user->last_role_check_at === null
            || $user->last_role_check_at->lt(now()->subMinutes($ttl));

        $tier = $user->tier;

        if ($stale) {
            if ($tier !== null) {
                $this->scheduleBackgroundRefresh($user->id);
            } else {
                // No cached tier - we can't admit them on hope; check now.
                $tier = RoleVerifier::fromConfig()->tierFor($user, force: true);
            }
        }

        if ($tier === null) {
            abort(403, 'You need a Raid Leader, Officer, Big6, or GuildMaster role in the Regenesis Discord to access this dashboard.');
        }

        return $next($request);
    }

    /**
     * Run the Discord role re-check after the response is flushed. The
     * in-flight key prevents a burst of concurrent requests each
     * scheduling their own duplicate refresh.
     */
    private function scheduleBackgroundRefresh(int $userId): void
    {
        $inFlightKey = "discord.tier.refresh.user.{$userId}";
        if (! Cache::add($inFlightKey, true, now()->addSeconds(30))) {
            return;
        }

        app()->terminating(function () use ($userId, $inFlightKey) {
            try {
                $user = User::find($userId);
                if ($user) {
                    RoleVerifier::fromConfig()->tierFor($user, force: true);
                }
            } finally {
                Cache::forget($inFlightKey);
            }
        });
    }
}
