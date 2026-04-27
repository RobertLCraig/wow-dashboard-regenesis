<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncBlizzardSnapshotJob;
use App\Models\Snapshot;
use App\Services\Blizzard\BlizzardClient;
use App\Services\Sync\SyncStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Officer button to fire a Blizzard sync. Same shape as
 * RaiderioSyncController: rate-limit per officer, short-circuit on
 * fresh snapshot, mutex-then-dispatch so two simultaneous clicks can't
 * both fire imports.
 */
class BlizzardSyncController extends Controller
{
    private const FRESH_TTL_SECONDS = 60;
    private const MUTEX_TTL_SECONDS = 300;

    public function store(): RedirectResponse
    {
        abort_unless(auth()->user()?->isOfficerTier(), 403);
        $guildKey = (string) config('grm.guild_key');
        $back = redirect()->route('admin.sync.index');

        if (! BlizzardClient::fromConfig()->isConfigured()) {
            return $back->withErrors([
                'blizzard' => 'Blizzard sync is not configured. Set BLIZZARD_CLIENT_ID and BLIZZARD_CLIENT_SECRET first.',
            ]);
        }

        $key = 'blizzard-sync:user:' . auth()->id();
        if (RateLimiter::tooManyAttempts($key, maxAttempts: 1)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            return $back->withErrors([
                'blizzard' => "Manual Blizzard sync is limited to once per hour. Try again in {$minutes} minute" . ($minutes === 1 ? '' : 's') . '.',
            ]);
        }

        $fresh = Snapshot::query()
            ->where('guild_key', $guildKey)
            ->where('source', Snapshot::SOURCE_BLIZZARD)
            ->where('captured_at', '>=', now()->subSeconds(self::FRESH_TTL_SECONDS))
            ->latest('captured_at')
            ->first();
        if ($fresh) {
            return $back->with('status', sprintf(
                'Skipped: Blizzard data is already fresh (%s, %d members in last snapshot).',
                $fresh->captured_at->diffForHumans(),
                $fresh->member_count ?? 0,
            ));
        }

        $lock = Cache::lock(SyncStatus::blizzardMutexKey(), self::MUTEX_TTL_SECONDS);
        if (! $lock->get()) {
            return $back->with('status', 'Another Blizzard sync is already running. Refresh in a few seconds.');
        }

        RateLimiter::hit($key, decaySeconds: 3600);

        SyncStatus::set(SyncStatus::SOURCE_BLIZZARD, [
            'status' => SyncStatus::QUEUED,
            'started_at' => now()->toIso8601String(),
            'started_by_user_id' => auth()->id(),
            'finished_at' => null,
            'summary' => null,
            'error' => null,
        ]);

        SyncBlizzardSnapshotJob::dispatch(
            guildKey: $guildKey,
            startedByUserId: auth()->id(),
            lockOwner: $lock->owner(),
        )->afterResponse();

        return $back->with('status', 'Blizzard sync started. This page will refresh while it runs.');
    }
}
