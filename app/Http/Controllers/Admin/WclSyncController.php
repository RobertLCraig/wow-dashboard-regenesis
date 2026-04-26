<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncWclReportsJob;
use App\Models\WclReport;
use App\Services\Sync\SyncStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Officer button to fire the WCL pull. Same shape as the RIO and
 * wowaudit sync controllers - rate limit, fresh-snapshot short-circuit,
 * mutex, dispatch-after-response.
 *
 * WCL data only changes after a raid night, so a 30-minute freshness
 * window is plenty - clicking twice in quick succession just hands you
 * back the existing summary instead of hitting their GraphQL again.
 */
class WclSyncController extends Controller
{
    private const FRESH_TTL_SECONDS = 1800;
    private const MUTEX_TTL_SECONDS = 180;

    public function store(): RedirectResponse
    {
        abort_unless(auth()->user()?->isOfficerTier(), 403);
        $back = redirect()->route('admin.sync.index');

        if (! config('wcl.client_id') || ! config('wcl.client_secret')) {
            return $back->withErrors([
                'wcl' => 'WCL_CLIENT_ID / WCL_CLIENT_SECRET not set. Register an API client at warcraftlogs.com/api/clients/ and add both to .env.',
            ]);
        }

        $key = 'wcl-sync:user:' . auth()->id();
        if (RateLimiter::tooManyAttempts($key, maxAttempts: 1)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            return $back->withErrors([
                'wcl' => "Manual WCL sync is limited to once per hour. Try again in {$minutes} minute" . ($minutes === 1 ? '' : 's') . '.',
            ]);
        }

        $fresh = WclReport::query()
            ->where('captured_at', '>=', now()->subSeconds(self::FRESH_TTL_SECONDS))
            ->latest('captured_at')
            ->first();
        if ($fresh) {
            return $back->with('status', sprintf(
                'Skipped: WCL data is already fresh (%s). Latest report: %s.',
                $fresh->captured_at->diffForHumans(),
                $fresh->title,
            ));
        }

        $lock = Cache::lock(SyncStatus::wclMutexKey(), self::MUTEX_TTL_SECONDS);
        if (! $lock->get()) {
            return $back->with('status', 'Another WCL sync is already running. Refresh in a few seconds.');
        }

        RateLimiter::hit($key, decaySeconds: 3600);

        SyncStatus::set(SyncStatus::SOURCE_WCL, [
            'status' => SyncStatus::QUEUED,
            'started_at' => now()->toIso8601String(),
            'started_by_user_id' => auth()->id(),
            'finished_at' => null,
            'summary' => null,
            'error' => null,
        ]);

        SyncWclReportsJob::dispatch(
            startedByUserId: auth()->id(),
            lockOwner: $lock->owner(),
        )->afterResponse();

        return $back->with('status', 'Warcraft Logs sync started. This page will refresh while it runs.');
    }
}
