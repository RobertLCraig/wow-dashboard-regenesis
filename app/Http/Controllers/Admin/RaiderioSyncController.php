<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Raiderio\RaiderioClient;
use App\Services\Raiderio\RaiderioSnapshotImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Officer button to fire the raiderio:pull importer immediately rather
 * than waiting for the next scheduled run. Same logic as the artisan
 * command; just gated on auth + officer tier and rate-limited so a
 * button-mash doesn't go round-tripping Raider.IO unnecessarily.
 *
 * Limit: 1/hour per officer. The scheduled twice-daily run keeps the
 * dashboard fresh; this is for "I just edited the team mapping and want
 * to see the widget update right now".
 */
class RaiderioSyncController extends Controller
{
    public function store(): RedirectResponse
    {
        abort_unless(auth()->user()?->isOfficerTier(), 403);

        $key = 'raiderio-sync:user:' . auth()->id();
        if (RateLimiter::tooManyAttempts($key, maxAttempts: 1)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            return redirect()
                ->route('admin.teams.index')
                ->withErrors(['raiderio' => "Manual RIO sync is limited to once per hour. Try again in {$minutes} minute" . ($minutes === 1 ? '' : 's') . '.']);
        }
        RateLimiter::hit($key, decaySeconds: 3600);

        try {
            $result = (new RaiderioSnapshotImporter(
                client: RaiderioClient::fromConfig(),
                guildKey: (string) config('grm.guild_key'),
                requestDelayMs: (int) config('raiderio.request_delay_ms', 100),
            ))->pull();
        } catch (\Throwable $e) {
            return redirect()
                ->route('admin.teams.index')
                ->withErrors(['raiderio' => 'RIO sync failed: ' . $e->getMessage()]);
        }

        return redirect()
            ->route('admin.teams.index')
            ->with('status', sprintf(
                'Raider.IO sync done: %d members queried, %d matched, %d missing on RIO, %d errored.',
                $result['members_queried'],
                $result['matched'],
                $result['missing'],
                $result['errored'],
            ));
    }
}
