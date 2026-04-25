<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies a Raid-Helper webhook delivery by comparing the inbound
 * Authorization header against config('raidhelper.webhook_key').
 *
 * Constant-time hash_equals; failing closed (500) when no key is
 * configured rather than accepting silently. Returns 401 on mismatch.
 */
class RaidHelperWebhookAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('raidhelper.webhook_key');
        if ($expected === '') {
            return response()->json(['error' => 'webhook key not configured'], 500);
        }

        $provided = (string) $request->header('Authorization', '');
        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['error' => 'unauthorised'], 401);
        }

        return $next($request);
    }
}
