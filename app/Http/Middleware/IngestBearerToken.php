<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Constant-time bearer token check for the GRM ingest endpoint.
 *
 * Token lives in config('grm.ingest_token') (env GRM_INGEST_TOKEN). The
 * PowerShell sync tool sends it in the Authorization header. We hash_equals
 * the raw token so a timing attack cannot leak it byte-by-byte.
 */
class IngestBearerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('grm.ingest_token');

        if ($expected === '') {
            // Misconfigured server: refuse rather than accept silently.
            return response()->json(['error' => 'ingest token not configured'], 500);
        }

        $header = (string) $request->header('Authorization', '');
        $provided = str_starts_with($header, 'Bearer ')
            ? substr($header, 7)
            : '';

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['error' => 'unauthorised'], 401);
        }

        return $next($request);
    }
}
