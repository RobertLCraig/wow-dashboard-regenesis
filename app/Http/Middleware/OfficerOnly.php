<?php

namespace App\Http\Middleware;

use App\Services\Discord\RoleVerifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate every dashboard route on Discord officer-tier membership.
 *
 * Trust the cached User#tier column for routine page loads, but force a
 * Discord re-check whenever it's stale (older than the configured TTL)
 * so that a removed role takes effect within ~5 minutes without the
 * user having to sign back in.
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

        if ($stale) {
            $tier = RoleVerifier::fromConfig()->tierFor($user, force: true);
        } else {
            $tier = $user->tier;
        }

        if ($tier === null) {
            abort(403, 'You need an Officer, Big6, or GuildMaster role in the Regenesis Discord to access this dashboard.');
        }

        return $next($request);
    }
}
