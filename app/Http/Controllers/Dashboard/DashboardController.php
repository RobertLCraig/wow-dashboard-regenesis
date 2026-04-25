<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\LogEvent;
use App\Models\Member;
use App\Models\MemberEvent;
use App\Models\Snapshot;
use Carbon\CarbonImmutable;
use Illuminate\View\View;

/**
 * Top-level dashboard. Gathers the data for the v1 widgets:
 *   - Roster Health Summary (stats row)
 *   - Recently Inactive (table)
 *   - Recent Log Timeline (activity feed)
 *
 * Each widget returns plain arrays / Eloquent collections; the Blade
 * partials handle presentation. No queries inside views.
 */
class DashboardController extends Controller
{
    public function index(): View
    {
        $guildKey = (string) config('grm.guild_key');
        $inactiveDays = (int) config('grm.inactive_days', 30);

        return view('dashboard.index', [
            'lastSnapshot' => Snapshot::query()
                ->where('guild_key', $guildKey)
                ->latest('captured_at')
                ->first(),
            'health' => $this->rosterHealth($guildKey, $inactiveDays),
            'inactive' => $this->recentlyInactive($guildKey, $inactiveDays),
            'timeline' => $this->recentLogTimeline($guildKey),
        ]);
    }

    /**
     * @return array{
     *   active: int,
     *   delta_7d: int,
     *   retention_30d_pct: ?float,
     *   avg_level: ?float,
     *   avg_days_since_online: ?float,
     *   inactive_count: int,
     *   total_known: int,
     * }
     */
    private function rosterHealth(string $guildKey, int $inactiveDays): array
    {
        $now = CarbonImmutable::now();
        $sevenDaysAgo = $now->subDays(7);
        $inactiveCutoff = $now->subDays($inactiveDays);

        $active = Member::active()->forGuild($guildKey)->count();

        // Net change in active count over the last 7 days. Joiners count
        // positively, leavers/kicks/bans negatively. Returns are also
        // joiners. Anniversaries / inactivity transitions don't count.
        $joiners = MemberEvent::query()
            ->whereHas('member', fn ($q) => $q->forGuild($guildKey))
            ->whereIn('type', [MemberEvent::TYPE_JOINED, MemberEvent::TYPE_RETURNED])
            ->where('occurred_at', '>=', $sevenDaysAgo)
            ->count();
        $leavers = MemberEvent::query()
            ->whereHas('member', fn ($q) => $q->forGuild($guildKey))
            ->whereIn('type', [MemberEvent::TYPE_LEFT, MemberEvent::TYPE_KICKED, MemberEvent::TYPE_BANNED])
            ->where('occurred_at', '>=', $sevenDaysAgo)
            ->count();

        // Retention: active members with last_online_at within the
        // inactive window. Crude but matches what officers actually look
        // at in GRM ("how many of our roster is still showing up").
        $activeRecent = Member::active()->forGuild($guildKey)
            ->where('last_online_at', '>=', $inactiveCutoff)
            ->count();
        $retention = $active > 0 ? round($activeRecent / $active * 100, 1) : null;

        $avgLevel = Member::active()->forGuild($guildKey)
            ->whereNotNull('level')
            ->avg('level');
        $avgLevel = $avgLevel !== null ? round((float) $avgLevel, 1) : null;

        // Average days since last seen. Online players (last_online_at
        // ~= captured_at) pull this toward 0; long-dormant alts push it
        // up. Used for the sparkline-headline number.
        $avgDays = null;
        $rows = Member::active()->forGuild($guildKey)
            ->whereNotNull('last_online_at')
            ->pluck('last_online_at');
        if ($rows->isNotEmpty()) {
            $avgDays = round($rows->avg(fn ($t) => $t->diffInHours($now) / 24), 1);
        }

        return [
            'active' => $active,
            'delta_7d' => $joiners - $leavers,
            'joiners_7d' => $joiners,
            'leavers_7d' => $leavers,
            'retention_pct' => $retention,
            'avg_level' => $avgLevel,
            'avg_days_since_online' => $avgDays,
            'inactive_count' => Member::active()->forGuild($guildKey)
                ->where('last_online_at', '<', $inactiveCutoff)
                ->count(),
            'total_known' => Member::forGuild($guildKey)->count(),
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, Member>
     */
    private function recentlyInactive(string $guildKey, int $inactiveDays): \Illuminate\Support\Collection
    {
        $cutoff = CarbonImmutable::now()->subDays($inactiveDays);
        return Member::active()
            ->forGuild($guildKey)
            ->whereNotNull('last_online_at')
            ->where('last_online_at', '<', $cutoff)
            ->orderBy('last_online_at', 'asc')
            ->limit(50)
            ->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, LogEvent>
     */
    private function recentLogTimeline(string $guildKey): \Illuminate\Support\Collection
    {
        return LogEvent::query()
            ->where('guild_key', $guildKey)
            ->orderBy('occurred_at', 'desc')
            ->limit(50)
            ->get();
    }
}
