<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Snapshot;
use App\Services\Raiderio\RaiderioClient;
use App\Services\Raiderio\RaiderioSnapshotImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Officer button to fire the raiderio:pull importer immediately rather
 * than waiting for the next scheduled run. Three concurrency guards in
 * order of cheapness:
 *
 *  1. Per-officer rate limit (1/hour) - blocks one officer button-mash.
 *  2. Recent-snapshot short-circuit - if the last raiderio snapshot is
 *     under fresh_ttl_seconds old, return its summary without calling
 *     RIO again. Catches "two officers click within 30s of each other".
 *  3. Global cache lock - only one importer process at a time across
 *     all officers / all hosts. Second concurrent caller gets an
 *     immediate "already running" response, not a queued wait.
 *
 * If anything past the rate-limiter fails, the rate-limit token is
 * released so the officer can retry without waiting an hour.
 */
class RaiderioSyncController extends Controller
{
    /** Seconds within which an existing snapshot is treated as fresh. */
    private const FRESH_TTL_SECONDS = 60;

    /** Lock TTL must exceed the worst-case importer wall time. */
    private const LOCK_TTL_SECONDS = 120;

    public function store(): RedirectResponse
    {
        abort_unless(auth()->user()?->isOfficerTier(), 403);
        $guildKey = (string) config('grm.guild_key');

        $key = 'raiderio-sync:user:' . auth()->id();
        if (RateLimiter::tooManyAttempts($key, maxAttempts: 1)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            return redirect()
                ->route('admin.teams.index')
                ->withErrors(['raiderio' => "Manual RIO sync is limited to once per hour. Try again in {$minutes} minute" . ($minutes === 1 ? '' : 's') . '.']);
        }

        // Recent-snapshot short-circuit. If we already pulled within the
        // last minute (manual or scheduled), the data won't have moved -
        // hand the officer the summary instead of re-firing 50 calls.
        $fresh = Snapshot::query()
            ->where('guild_key', $guildKey)
            ->where('source', Snapshot::SOURCE_RAIDERIO)
            ->where('captured_at', '>=', now()->subSeconds(self::FRESH_TTL_SECONDS))
            ->latest('captured_at')
            ->first();
        if ($fresh) {
            return redirect()
                ->route('admin.teams.index')
                ->with('status', sprintf(
                    'Skipped: Raider.IO data is already fresh (%s, %d members in last snapshot).',
                    $fresh->captured_at->diffForHumans(),
                    $fresh->member_count ?? 0,
                ));
        }

        // Global mutex so concurrent officer clicks don't both blast RIO.
        // Block-for-zero means "fail immediately if held" rather than
        // queuing - second caller sees "already running" and can refresh.
        $lock = Cache::lock('raiderio-sync:running', self::LOCK_TTL_SECONDS);
        if (! $lock->get()) {
            return redirect()
                ->route('admin.teams.index')
                ->with('status', 'Another sync is already running. Refresh in a few seconds.');
        }

        RateLimiter::hit($key, decaySeconds: 3600);

        // Importer fans out via Http::pool but a slow RIO can still bump
        // up against PHP's default 30s ceiling on shared hosting.
        @set_time_limit(self::LOCK_TTL_SECONDS);

        try {
            $result = (new RaiderioSnapshotImporter(
                client: RaiderioClient::fromConfig(),
                guildKey: $guildKey,
                requestDelayMs: (int) config('raiderio.request_delay_ms', 100),
                concurrency: (int) config('raiderio.sync_concurrency', 10),
            ))->pull();
        } catch (\Throwable $e) {
            RateLimiter::clear($key);
            return redirect()
                ->route('admin.teams.index')
                ->withErrors(['raiderio' => 'RIO sync failed: ' . $e->getMessage()]);
        } finally {
            $lock->release();
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
