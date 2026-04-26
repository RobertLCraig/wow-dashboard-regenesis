<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncWowauditSnapshotJob;
use App\Models\Snapshot;
use App\Services\Sync\SyncStatus;
use App\Services\Wowaudit\WowauditClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Officer button to fire the wowaudit pull. Same shape as the RIO
 * controller (rate limit + recent-snapshot short-circuit + mutex +
 * dispatch-after-response) so the sync page can treat both panels
 * identically.
 *
 * Wowaudit's data updates more slowly than RIO (it pulls from Blizzard
 * once per character session) so a 5-minute freshness window is fine.
 */
class WowauditSyncController extends Controller
{
    private const FRESH_TTL_SECONDS = 300;
    private const MUTEX_TTL_SECONDS = 180;

    public function store(): RedirectResponse
    {
        abort_unless(auth()->user()?->isOfficerTier(), 403);
        $back = redirect()->route('admin.sync.index');

        if (! WowauditClient::fromConfig()->isConfigured()) {
            return $back->withErrors([
                'wowaudit' => 'WOWAUDIT_API_KEY is not set. Add it to .env (see Settings -> API on wowaudit.com).',
            ]);
        }

        $guildKey = (string) config('grm.guild_key');

        $key = 'wowaudit-sync:user:' . auth()->id();
        if (RateLimiter::tooManyAttempts($key, maxAttempts: 1)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            return $back->withErrors([
                'wowaudit' => "Manual wowaudit sync is limited to once per hour. Try again in {$minutes} minute" . ($minutes === 1 ? '' : 's') . '.',
            ]);
        }

        $fresh = Snapshot::query()
            ->where('guild_key', $guildKey)
            ->where('source', Snapshot::SOURCE_WOWAUDIT)
            ->where('captured_at', '>=', now()->subSeconds(self::FRESH_TTL_SECONDS))
            ->latest('captured_at')
            ->first();
        if ($fresh) {
            return $back->with('status', sprintf(
                'Skipped: wowaudit data is already fresh (%s, %d members in last snapshot).',
                $fresh->captured_at->diffForHumans(),
                $fresh->member_count ?? 0,
            ));
        }

        $lock = Cache::lock(SyncStatus::wowauditMutexKey(), self::MUTEX_TTL_SECONDS);
        if (! $lock->get()) {
            return $back->with('status', 'Another wowaudit sync is already running. Refresh in a few seconds.');
        }

        RateLimiter::hit($key, decaySeconds: 3600);

        SyncStatus::set(SyncStatus::SOURCE_WOWAUDIT, [
            'status' => SyncStatus::QUEUED,
            'started_at' => now()->toIso8601String(),
            'started_by_user_id' => auth()->id(),
            'finished_at' => null,
            'summary' => null,
            'error' => null,
        ]);

        SyncWowauditSnapshotJob::dispatch(
            guildKey: $guildKey,
            startedByUserId: auth()->id(),
            lockOwner: $lock->owner(),
        )->afterResponse();

        return $back->with('status', 'Wowaudit sync started. This page will refresh while it runs.');
    }
}
