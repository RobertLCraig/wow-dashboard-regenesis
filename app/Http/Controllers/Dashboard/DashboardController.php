<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\LogEvent;
use App\Models\Member;
use App\Models\MemberAction;
use App\Models\MemberEvent;
use App\Models\MemberSnapshot;
use App\Models\RaidEvent;
use App\Models\Snapshot;
use App\Models\TeamMapping;
use App\Services\Attendance\AttendanceReconciler;
use App\Services\Dashboard\WidgetOrderResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * General guild management dashboard at /dashboard. Guild-wide rollups
 * (roster health, action queue, churn, anniversaries, all-team
 * progression overview, all-channel upcoming events). Team-detail
 * widgets (per-team attendance, vault, M+ this week) live on the
 * per-team pages under TeamDashboardController.
 */
class DashboardController extends Controller
{
    public function index(): View
    {
        abort_unless(auth()->user()?->can('dashboard.general.view'), 403);

        $guildKey = (string) config('grm.guild_key');
        $inactiveDays = (int) config('grm.inactive_days', 30);

        $widgetData = [
            'health' => $this->rosterHealth($guildKey, $inactiveDays),
            'timeline' => $this->recentLogTimeline($guildKey),
            'actionQueue' => $this->actionQueue($guildKey),
            'bans' => $this->bans($guildKey),
            'anniversaries' => $this->anniversaries($guildKey),
            'rankDistribution' => $this->rankDistribution($guildKey),
            'churn' => $this->churn($guildKey),
            'upcomingEvents' => $this->upcomingEvents(),
            'teamProgression' => $this->teamProgression($guildKey),
            'raidAttendance' => (new AttendanceReconciler)->recent($guildKey),
        ];

        $widgets = WidgetOrderResolver::resolve(
            available: (array) config('dashboard.widgets', []),
            userOrder: auth()->user()?->dashboard_layout,
        );

        return view('dashboard.index', [
            'lastSnapshot' => Snapshot::query()
                ->where('guild_key', $guildKey)
                ->latest('captured_at')
                ->first(),
            'widgets' => $widgets,
            'widgetData' => $widgetData,
        ]);
    }

    /**
     * Per-team summary built from the latest Raider.IO snapshot. Groups
     * active members by members.team and rolls up best raid progression,
     * average ilvl, top RIO score, and top weekly key per team.
     *
     * Empty teams are dropped so the widget only renders teams that
     * actually have someone on them.
     *
     * @return array{captured_at: ?\Carbon\CarbonInterface, teams: array<string,array{count:int,with_data:int,best_raid_summary:?string,best_raid_key:?string,avg_ilvl:?int,top_rio:?float,top_key:?int}>}
     */
    private function teamProgression(string $guildKey): array
    {
        $latest = Snapshot::query()
            ->where('guild_key', $guildKey)
            ->where('source', Snapshot::SOURCE_RAIDERIO)
            ->latest('captured_at')
            ->first();

        // Even with no raiderio snapshot we still group active members by
        // team so officers see who's on which team in the empty state.
        $membersByTeam = Member::query()
            ->forGuild($guildKey)
            ->active()
            ->whereNotNull('team')
            ->get()
            ->groupBy('team');

        $snapsByMember = collect();
        if ($latest) {
            $snapsByMember = MemberSnapshot::query()
                ->where('snapshot_id', $latest->id)
                ->get()
                ->keyBy('member_id');
        }

        $teams = [];
        foreach (TeamMapping::TEAMS as $team) {
            $members = $membersByTeam->get($team, collect());
            if ($members->isEmpty()) {
                continue;
            }

            $snaps = $members
                ->map(fn ($m) => $snapsByMember->get($m->id))
                ->filter();

            // Best raid progression across the team: prefer most mythic
            // kills, then most heroic kills as a tiebreaker.
            $bestSummary = null;
            $bestKey = null;
            $bestM = -1;
            $bestH = -1;
            foreach ($snaps as $snap) {
                foreach ((array) ($snap->raid_progression_json ?? []) as $instanceKey => $p) {
                    if (! is_array($p)) {
                        continue;
                    }
                    $m = (int) ($p['mythic_bosses_killed'] ?? 0);
                    $h = (int) ($p['heroic_bosses_killed'] ?? 0);
                    if ($m > $bestM || ($m === $bestM && $h > $bestH)) {
                        $bestM = $m;
                        $bestH = $h;
                        $bestSummary = is_string($p['summary'] ?? null) ? $p['summary'] : null;
                        $bestKey = $instanceKey;
                    }
                }
            }

            $ilvls = $snaps->pluck('ilvl')->filter()->all();
            $rios = $snaps->pluck('mplus_score')->filter()->all();
            $keys = $snaps->pluck('mplus_keystone')->filter()->all();

            $teams[$team] = [
                'count' => $members->count(),
                'with_data' => $snaps->count(),
                'best_raid_summary' => $bestSummary,
                'best_raid_key' => $bestKey,
                'avg_ilvl' => $ilvls ? (int) round(array_sum($ilvls) / count($ilvls)) : null,
                'top_rio' => $rios ? (float) max($rios) : null,
                'top_key' => $keys ? (int) max($keys) : null,
            ];
        }

        return [
            'captured_at' => $latest?->captured_at,
            'teams' => $teams,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, RaidEvent>
     */
    private function upcomingEvents(): \Illuminate\Support\Collection
    {
        return RaidEvent::query()
            ->upcoming()
            ->withCount('signups')
            ->limit(10)
            ->get();
    }

    private function rosterHealth(string $guildKey, int $inactiveDays): array
    {
        $now = CarbonImmutable::now();
        $sevenDaysAgo = $now->subDays(7);
        $inactiveCutoff = $now->subDays($inactiveDays);

        $active = Member::active()->forGuild($guildKey)->count();

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

        $activeRecent = Member::active()->forGuild($guildKey)
            ->where('last_online_at', '>=', $inactiveCutoff)
            ->count();
        $retention = $active > 0 ? round($activeRecent / $active * 100, 1) : null;

        $avgLevel = Member::active()->forGuild($guildKey)
            ->whereNotNull('level')
            ->avg('level');
        $avgLevel = $avgLevel !== null ? round((float) $avgLevel, 1) : null;

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

    private function recentLogTimeline(string $guildKey): \Illuminate\Support\Collection
    {
        return LogEvent::query()
            ->where('guild_key', $guildKey)
            ->orderBy('occurred_at', 'desc')
            ->limit(50)
            ->get();
    }

    /**
     * Action queue: members GRM has flagged for promote/demote/kick that
     * an officer hasn't already accepted or dismissed.
     */
    private function actionQueue(string $guildKey): array
    {
        $reviewedIds = MemberAction::query()
            ->whereIn('decision', [MemberAction::DECISION_ACCEPTED, MemberAction::DECISION_DISMISSED])
            ->orWhere(function (Builder $q) {
                $q->where('decision', MemberAction::DECISION_SNOOZED)
                  ->where('snooze_until', '>', now());
            })
            ->pluck('action_type', 'member_id');

        $base = fn (Builder $q) => $q->forGuild($guildKey)->orderBy('name');

        $filterReviewed = function ($collection, string $type) use ($reviewedIds) {
            return $collection->reject(function ($member) use ($reviewedIds, $type) {
                return ($reviewedIds[$member->id] ?? null) === $type;
            })->values();
        };

        return [
            'promote' => $filterReviewed(
                $base(Member::query()->where('recommend_promote', true)->active())->get(),
                MemberAction::TYPE_PROMOTE,
            ),
            'demote' => $filterReviewed(
                $base(Member::query()->where('recommend_demote', true)->active())->get(),
                MemberAction::TYPE_DEMOTE,
            ),
            'kick' => $filterReviewed(
                $base(Member::query()->where('recommend_kick', true)->active())->get(),
                MemberAction::TYPE_KICK,
            ),
        ];
    }

    private function bans(string $guildKey): \Illuminate\Support\Collection
    {
        return Member::query()
            ->forGuild($guildKey)
            ->where('status', Member::STATUS_BANNED)
            ->orderByDesc('banned_at')
            ->get();
    }

    private function anniversaries(string $guildKey): \Illuminate\Support\Collection
    {
        $weekStart = CarbonImmutable::now()->startOfWeek();
        $weekEnd = $weekStart->endOfWeek();

        return MemberEvent::query()
            ->where('type', MemberEvent::TYPE_ANNIVERSARY)
            ->whereBetween('occurred_at', [$weekStart, $weekEnd])
            ->whereHas('member', fn ($q) => $q->forGuild($guildKey))
            ->with('member')
            ->orderBy('occurred_at')
            ->get();
    }

    /**
     * @return array<int,array{rank:string,count:int,index:?int}>
     */
    private function rankDistribution(string $guildKey): array
    {
        return Member::active()
            ->forGuild($guildKey)
            ->select('rank_name', 'rank_index', DB::raw('count(*) as c'))
            ->groupBy('rank_name', 'rank_index')
            ->orderBy('rank_index')
            ->get()
            ->map(fn ($row) => [
                'rank' => $row->rank_name ?? '(none)',
                'count' => (int) $row->c,
                'index' => $row->rank_index,
            ])
            ->all();
    }

    /**
     * Weekly joiner / leaver counts over the last 12 weeks for the
     * churn chart. Returns rows in chronological order so Chart.js
     * can render them as-is.
     *
     * @return array{labels:list<string>, joiners:list<int>, leavers:list<int>}
     */
    private function churn(string $guildKey): array
    {
        $weeks = 12;
        $start = CarbonImmutable::now()->startOfWeek()->subWeeks($weeks - 1);
        $labels = [];
        $joiners = [];
        $leavers = [];

        for ($i = 0; $i < $weeks; $i++) {
            $weekStart = $start->addWeeks($i);
            $weekEnd = $weekStart->endOfWeek();
            $labels[] = $weekStart->format('d M');

            $joiners[] = MemberEvent::query()
                ->whereHas('member', fn ($q) => $q->forGuild($guildKey))
                ->whereIn('type', [MemberEvent::TYPE_JOINED, MemberEvent::TYPE_RETURNED])
                ->whereBetween('occurred_at', [$weekStart, $weekEnd])
                ->count();
            $leavers[] = MemberEvent::query()
                ->whereHas('member', fn ($q) => $q->forGuild($guildKey))
                ->whereIn('type', [MemberEvent::TYPE_LEFT, MemberEvent::TYPE_KICKED, MemberEvent::TYPE_BANNED])
                ->whereBetween('occurred_at', [$weekStart, $weekEnd])
                ->count();
        }

        return ['labels' => $labels, 'joiners' => $joiners, 'leavers' => $leavers];
    }
}
