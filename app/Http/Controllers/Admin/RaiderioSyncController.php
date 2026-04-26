<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncRaiderioSnapshotJob;
use App\Models\Snapshot;
use App\Services\Sync\SyncStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Officer button to fire a Raider.IO sync. Dispatches a queued job
 * (after-response, so the request returns immediately) rather than
 * running the importer inline - the previous synchronous design hit
 * nginx's 60s gateway timeout when RIO was slow.
 *
 * Three concurrency guards before dispatch:
 *
 *  1. Per-officer rate limit (1/hour) - blocks one officer button-mash.
 *  2. Recent-snapshot short-circuit - if the last snapshot is under
 *     fresh_ttl_seconds old, return its summary without dispatching.
 *  3. Cache::lock mutex (transferred to the job via owner token) so two
 *     officers clicking simultaneously can't both fire imports.
 */
class RaiderioSyncController extends Controller
{
    private const FRESH_TTL_SECONDS = 60;
    private const MUTEX_TTL_SECONDS = 300;

    public function store(): RedirectResponse
    {
        abort_unless(auth()->user()?->isOfficerTier(), 403);
        $guildKey = (string) config('grm.guild_key');
        $back = redirect()->route('admin.sync.index');

        $key = 'raiderio-sync:user:' . auth()->id();
        if (RateLimiter::tooManyAttempts($key, maxAttempts: 1)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            return $back->withErrors([
                'raiderio' => "Manual RIO sync is limited to once per hour. Try again in {$minutes} minute" . ($minutes === 1 ? '' : 's') . '.',
            ]);
        }

        $fresh = Snapshot::query()
            ->where('guild_key', $guildKey)
            ->where('source', Snapshot::SOURCE_RAIDERIO)
            ->where('captured_at', '>=', now()->subSeconds(self::FRESH_TTL_SECONDS))
            ->latest('captured_at')
            ->first();
        if ($fresh) {
            return $back->with('status', sprintf(
                'Skipped: Raider.IO data is already fresh (%s, %d members in last snapshot).',
                $fresh->captured_at->diffForHumans(),
                $fresh->member_count ?? 0,
            ));
        }

        // Acquire the mutex up front so a second simultaneous click fails
        // immediately. The job inherits the lock via the owner token and
        // releases it when done (or on failure).
        $lock = Cache::lock(SyncStatus::raiderioMutexKey(), self::MUTEX_TTL_SECONDS);
        if (! $lock->get()) {
            return $back->with('status', 'Another Raider.IO sync is already running. Refresh in a few seconds.');
        }

        RateLimiter::hit($key, decaySeconds: 3600);

        SyncStatus::set(SyncStatus::SOURCE_RAIDERIO, [
            'status' => SyncStatus::QUEUED,
            'started_at' => now()->toIso8601String(),
            'started_by_user_id' => auth()->id(),
            'finished_at' => null,
            'summary' => null,
            'error' => null,
        ]);

        SyncRaiderioSnapshotJob::dispatch(
            guildKey: $guildKey,
            startedByUserId: auth()->id(),
            lockOwner: $lock->owner(),
        )->afterResponse();

        return $back->with('status', 'Raider.IO sync started. This page will refresh while it runs.');
    }
}
