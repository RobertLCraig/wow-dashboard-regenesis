<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberSnapshot;
use App\Models\RaidEvent;
use App\Models\Snapshot;
use Illuminate\View\View;

/**
 * Standalone page for organised mythic+ keynight (typically Mondays).
 *
 * Distinct from the raid team pages: M+ dungeons are a separate
 * activity from mythic raid difficulty, with their own signup channel
 * and a participant pool that spans both heroic and mythic raiders.
 * Lives at /dashboard/keynight so either crowd can find it without
 * digging through the other team's page.
 *
 * No team-membership filter here - anyone with an M+ score is eligible.
 * Scoreboard sorts by current-season RIO score; the event list filters
 * to the keynight signup channel.
 */
class KeynightController extends Controller
{
    private const SCOREBOARD_LIMIT = 30;

    public function index(): View
    {
        abort_unless(auth()->user()?->can('dashboard.keynight.view'), 403);

        $guildKey = (string) config('grm.guild_key');
        $preset = (array) config('raidhelper.teams.keynight');

        return view('dashboard.keynight', [
            'lastSnapshot' => Snapshot::query()
                ->where('guild_key', $guildKey)
                ->latest('captured_at')
                ->first(),
            'preset' => $preset,
            'scoreboard' => $this->scoreboard($guildKey),
            'upcomingEvents' => $this->upcomingEvents($preset['channel_id'] ?? null),
        ]);
    }

    /**
     * @return array{captured_at: ?\Carbon\CarbonInterface, rows: \Illuminate\Support\Collection}
     */
    private function scoreboard(string $guildKey): array
    {
        $latest = Snapshot::query()
            ->where('guild_key', $guildKey)
            ->where('source', Snapshot::SOURCE_RAIDERIO)
            ->latest('captured_at')
            ->first();

        if (! $latest) {
            return ['captured_at' => null, 'rows' => collect()];
        }

        // Pull every snapshot row from the latest pull, drop ones whose
        // member is gone (left/banned), then take the top scorers.
        $rows = MemberSnapshot::query()
            ->where('snapshot_id', $latest->id)
            ->with('member')
            ->whereNotNull('mplus_score')
            ->orderByDesc('mplus_score')
            ->limit(self::SCOREBOARD_LIMIT)
            ->get()
            ->filter(fn ($s) => $s->member !== null && $s->member->status === Member::STATUS_ACTIVE)
            ->values();

        return ['captured_at' => $latest->captured_at, 'rows' => $rows];
    }

    /**
     * @return \Illuminate\Support\Collection<int, RaidEvent>
     */
    private function upcomingEvents(?string $channelId): \Illuminate\Support\Collection
    {
        if (! $channelId) {
            return collect();
        }
        return RaidEvent::query()
            ->upcoming()
            ->where('channel_id', $channelId)
            ->withCount('signups')
            ->limit(10)
            ->get();
    }
}
