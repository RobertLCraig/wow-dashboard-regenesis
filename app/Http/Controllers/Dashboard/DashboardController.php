<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\LogEvent;
use App\Models\Member;
use App\Models\MemberAction;
use App\Models\MemberEvent;
use App\Models\MemberRaidSnapshot;
use App\Models\MemberSnapshot;
use App\Models\RaidEvent;
use App\Models\Snapshot;
use App\Models\TeamMapping;
use App\Services\Attendance\AttendanceReconciler;
use App\Services\Blizzard\AotcCohortGapBuilder;
use App\Services\Blizzard\RaidProgressionAnalyzer;
use App\Services\Dashboard\WidgetOrderResolver;
use App\Services\Wcl\DeathCauseAggregator;
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
            'aotcGap' => $this->aotcGap($guildKey),
            'deathCauses' => (new DeathCauseAggregator($guildKey))->topByEncounter(),
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
     * Per-team summary built from the latest Raider.IO snapshot, plus a
     * unified raid-by-raid breakdown rolled out of the latest Blizzard
     * raid-encounters snapshot. The shape is built around scannability:
     * a comparison row keyed by team for shared metrics (Members, ilvl,
     * RIO, key, best raid), and a list of raid blocks where each
     * difficulty groups team-level progress rows side by side.
     *
     * Empty teams are dropped so the widget only renders teams that
     * actually have someone on them. The boss breakdown is filtered to
     * the current tier (latest expansion seen across the guild's raid
     * snapshots), so old expansions don't bloat the panel.
     *
     * @return array{
     *   captured_at: ?\Carbon\CarbonInterface,
     *   breakdown_captured_at: ?\Carbon\CarbonInterface,
     *   current_tier: ?array{expansion_id:int, expansion_name:string, instance_id:int, instance_name:string},
     *   summary: array{team_count:int, raider_count:int, top_kills:array<string,array{killed:int,total:int,team:?string}>},
     *   teams: array<string,array{label:string,count:int,with_data:int,max_difficulty:string,best_raid_summary:?string,best_raid_key:?string,avg_ilvl:?int,top_rio:?float,top_key:?int}>,
     *   raids: list<array{instance_id:int,name:string,difficulties:list<array{type:string,label:string,short:string,team_rows:list<array<string,mixed>>}>}>,
     *   insights: list<string>
     * }
     */
    private function teamProgression(string $guildKey): array
    {
        $latest = Snapshot::query()
            ->where('guild_key', $guildKey)
            ->where('source', Snapshot::SOURCE_RAIDERIO)
            ->latest('captured_at')
            ->first();
        $latestRaids = Snapshot::query()
            ->where('guild_key', $guildKey)
            ->where('source', Snapshot::SOURCE_BLIZZARD_RAIDS)
            ->latest('captured_at')
            ->first();

        // Even with no raiderio snapshot we still group active members by
        // team so officers see who's on which team in the empty state.
        // groupByTeam() handles members on multiple teams by pushing
        // them into each team's bucket; raiderCount is the distinct
        // count taken before the duplication so the summary reads true.
        $rosterMembers = Member::query()
            ->forGuild($guildKey)
            ->active()
            ->hasAnyTeam()
            ->with('teams')
            ->get();
        $raiderCount = $rosterMembers->count();
        $membersByTeam = Member::groupByTeam($rosterMembers);

        $snapsByMember = collect();
        if ($latest) {
            $snapsByMember = MemberSnapshot::query()
                ->where('snapshot_id', $latest->id)
                ->get()
                ->keyBy('member_id');
        }

        $raidSnapsByMember = collect();
        if ($latestRaids) {
            $raidSnapsByMember = MemberRaidSnapshot::query()
                ->where('snapshot_id', $latestRaids->id)
                ->get()
                ->keyBy('member_id');
        }
        $analyzer = new RaidProgressionAnalyzer();

        // Lock the boss-by-boss breakdown to the current tier (latest
        // expansion seen across the whole guild's raid snapshots). Older
        // expansions still live in the snapshot payload, so without this
        // filter the widget would render every prior tier's boss list
        // alongside the current one. Computed once at this scope so every
        // team's panel resolves to the same season.
        $currentTier = $raidSnapsByMember->isNotEmpty()
            ? $analyzer->currentTier($raidSnapsByMember->values())
            : null;
        $currentExpansionId = $currentTier['expansion_id'] ?? null;

        $teams = [];
        $rawBreakdownByTeam = [];
        foreach (TeamMapping::TEAMS as $team) {
            $members = $membersByTeam->get($team, collect());
            if ($members->isEmpty()) {
                continue;
            }

            $snaps = $members
                ->map(fn ($m) => $snapsByMember->get($m->id))
                ->filter();

            // Best raid progression for the team, capped to the team's
            // own raid difficulty: Heroic teams shouldn't read "4/9 M"
            // just because one member did some Mythic kills with the
            // Mythic team. Difficulty preference inside the cap:
            // mythic > heroic > normal, then most kills wins. Summary
            // is synthesized from the raw counts so it always matches
            // the cap (Raider.IO's own summary string includes mythic
            // and would re-leak the bug).
            $maxDiff = TeamMapping::maxDifficultyFor($team);
            $bestKey = null;
            $bestM = -1;
            $bestH = -1;
            $bestN = -1;
            $bestTotal = 0;
            foreach ($snaps as $snap) {
                foreach ((array) ($snap->raid_progression_json ?? []) as $instanceKey => $p) {
                    if (! is_array($p)) {
                        continue;
                    }
                    $total = (int) ($p['total_bosses'] ?? 0);
                    $m = $maxDiff === 'mythic' ? (int) ($p['mythic_bosses_killed'] ?? 0) : 0;
                    $h = in_array($maxDiff, ['mythic', 'heroic'], true) ? (int) ($p['heroic_bosses_killed'] ?? 0) : 0;
                    $n = (int) ($p['normal_bosses_killed'] ?? 0);

                    if ($m > $bestM
                        || ($m === $bestM && $h > $bestH)
                        || ($m === $bestM && $h === $bestH && $n > $bestN)) {
                        $bestM = $m;
                        $bestH = $h;
                        $bestN = $n;
                        $bestTotal = $total;
                        $bestKey = $instanceKey;
                    }
                }
            }
            $bestSummary = match (true) {
                $bestM > 0 => "{$bestM}/{$bestTotal} M",
                $bestH > 0 => "{$bestH}/{$bestTotal} H",
                $bestN > 0 => "{$bestN}/{$bestTotal} N",
                default    => null,
            };

            $ilvls = $snaps->pluck('ilvl')->filter()->all();
            $rios = $snaps->pluck('mplus_score')->filter()->all();
            $keys = $snaps->pluck('mplus_keystone')->filter()->all();

            $teamRaidSnaps = $members
                ->map(fn ($m) => $raidSnapsByMember->get($m->id))
                ->filter()
                ->values();
            $rawBreakdownByTeam[$team] = $teamRaidSnaps->isNotEmpty()
                ? $analyzer->teamBossBreakdown($teamRaidSnaps, $maxDiff, $currentExpansionId)
                : [];

            $teams[$team] = [
                'label' => TeamMapping::teamLabel($team),
                'count' => $members->count(),
                'with_data' => $snaps->count(),
                'max_difficulty' => $maxDiff,
                'best_raid_summary' => $bestSummary,
                'best_raid_key' => $bestKey,
                'avg_ilvl' => $ilvls ? (int) round(array_sum($ilvls) / count($ilvls)) : null,
                'top_rio' => $rios ? (float) max($rios) : null,
                'top_key' => $keys ? (int) max($keys) : null,
            ];
        }

        $raids = $this->raidsFromTeamBreakdowns($rawBreakdownByTeam);
        $summary = $this->teamProgressionSummary($teams, $raiderCount, $raids);
        $insights = $this->teamProgressionInsights($teams, $raids);

        return [
            'captured_at' => $latest?->captured_at,
            'breakdown_captured_at' => $raids !== [] ? $latestRaids?->captured_at : null,
            'current_tier' => $currentTier,
            'summary' => $summary,
            'teams' => $teams,
            'raids' => $raids,
            'insights' => $insights,
        ];
    }

    /**
     * Pivot per-team boss breakdowns into a raid-first list. Each raid
     * groups its difficulties (M, then H, then N) and each difficulty
     * holds one row per team that runs at that difficulty. Bosses are
     * carried through verbatim, ordered by encounter id so the raid's
     * intended boss order survives the pivot.
     *
     * @param  array<string,list<array<string,mixed>>>  $byTeam
     * @return list<array{instance_id:int,name:string,difficulties:list<array{type:string,label:string,short:string,team_rows:list<array<string,mixed>>}>}>
     */
    private function raidsFromTeamBreakdowns(array $byTeam): array
    {
        // Difficulty render order: M > H > N. Anything outside this set
        // is dropped (the analyzer never emits LFR but stay defensive).
        $difficultyOrder = ['MYTHIC' => 0, 'HEROIC' => 1, 'NORMAL' => 2];
        $teamOrder = array_flip(TeamMapping::TEAMS);

        $raids = [];
        foreach ($byTeam as $team => $breakdown) {
            foreach ($breakdown as $raid) {
                $instanceId = $raid['id'];
                if (! isset($raids[$instanceId])) {
                    $raids[$instanceId] = [
                        'instance_id' => $instanceId,
                        'name' => $raid['name'] ?? '',
                        'difficulties' => [],
                    ];
                } elseif (($raids[$instanceId]['name'] === '') && ! empty($raid['name'])) {
                    $raids[$instanceId]['name'] = $raid['name'];
                }

                foreach ($raid['difficulties'] as $diff) {
                    $type = $diff['type'];
                    if (! isset($difficultyOrder[$type])) {
                        continue;
                    }
                    if (! isset($raids[$instanceId]['difficulties'][$type])) {
                        $raids[$instanceId]['difficulties'][$type] = [
                            'type' => $type,
                            'label' => $diff['label'],
                            'short' => $diff['short'],
                            'team_rows' => [],
                        ];
                    }
                    $raids[$instanceId]['difficulties'][$type]['team_rows'][] = [
                        'team' => $team,
                        'team_label' => TeamMapping::teamLabel($team),
                        'killed' => $diff['killed'],
                        'total' => $diff['total'],
                        'pct' => $diff['total'] > 0 ? (int) round($diff['killed'] / $diff['total'] * 100) : 0,
                        'encounters' => $diff['encounters'],
                    ];
                }
            }
        }

        $out = [];
        foreach ($raids as $raid) {
            $diffs = $raid['difficulties'];
            uksort($diffs, fn ($a, $b) => $difficultyOrder[$a] <=> $difficultyOrder[$b]);
            foreach ($diffs as &$diff) {
                usort(
                    $diff['team_rows'],
                    fn ($a, $b) => ($teamOrder[$a['team']] ?? 99) <=> ($teamOrder[$b['team']] ?? 99),
                );
            }
            unset($diff);
            $raid['difficulties'] = array_values($diffs);
            $out[] = $raid;
        }

        // Newest instance first so the active raid leads. Instance ids
        // are monotonically increasing per Blizzard's catalogue, same
        // tie-break the analyzer's currentTier() uses.
        usort($out, fn ($a, $b) => $b['instance_id'] <=> $a['instance_id']);

        return $out;
    }

    /**
     * Build the top-of-widget summary chip data: how many teams +
     * raiders are represented, and the top "X/Y D" kill summary per
     * difficulty across every team. The numbers are taken straight
     * from the pivoted raid list so they always match what the boss
     * breakdown shows.
     *
     * @param  array<string,array<string,mixed>>  $teams
     * @param  list<array{difficulties:list<array<string,mixed>>}>  $raids
     * @return array{team_count:int, raider_count:int, top_kills:array<string,array{killed:int,total:int,team:?string}>}
     */
    private function teamProgressionSummary(array $teams, int $raiderCount, array $raids): array
    {
        $top = [];
        foreach ($raids as $raid) {
            foreach ($raid['difficulties'] as $diff) {
                $type = $diff['type'];
                foreach ($diff['team_rows'] as $row) {
                    $current = $top[$type] ?? null;
                    if ($current === null
                        || $row['killed'] > $current['killed']
                        || ($row['killed'] === $current['killed'] && $row['total'] > $current['total'])) {
                        $top[$type] = [
                            'killed' => (int) $row['killed'],
                            'total' => (int) $row['total'],
                            'team' => $row['team'],
                        ];
                    }
                }
            }
        }

        return [
            'team_count' => count($teams),
            'raider_count' => $raiderCount,
            'top_kills' => $top,
        ];
    }

    /**
     * Auto-generated comparison lines that surface the "so what" of the
     * widget: which team is more geared, AOTC status, mythic progress,
     * etc. Empty list when nothing interesting can be said yet (no data
     * or only one team) so the view can hide the section cleanly.
     *
     * Kept deliberately small: each line should answer a question an
     * officer would otherwise ask aloud while looking at the table.
     *
     * @param  array<string,array<string,mixed>>  $teams
     * @param  list<array{difficulties:list<array<string,mixed>>}>  $raids
     * @return list<string>
     */
    private function teamProgressionInsights(array $teams, array $raids): array
    {
        $out = [];

        $mythic = $teams[TeamMapping::TEAM_MYTHIC] ?? null;
        $heroic = $teams[TeamMapping::TEAM_HEROIC] ?? null;
        if ($mythic && $heroic && $mythic['avg_ilvl'] && $heroic['avg_ilvl']) {
            $delta = $mythic['avg_ilvl'] - $heroic['avg_ilvl'];
            if ($delta !== 0) {
                $sign = $delta > 0 ? '+' : '';
                $out[] = "Mythic team avg ilvl {$mythic['avg_ilvl']} vs Heroic {$heroic['avg_ilvl']} ({$sign}{$delta}).";
            }
        }

        // Walk the active raid for "AOTC done" / "X to CE" style hints.
        // Only the newest raid (first entry, instance_id desc) drives
        // these so older tiers in the snapshot don't muddy them.
        $activeRaid = $raids[0] ?? null;
        if ($activeRaid !== null) {
            foreach ($activeRaid['difficulties'] as $diff) {
                foreach ($diff['team_rows'] as $row) {
                    if ($row['total'] === 0) {
                        continue;
                    }
                    if ($diff['type'] === 'HEROIC' && $row['killed'] === $row['total']) {
                        $out[] = "{$row['team_label']} team has cleared every Heroic boss in {$activeRaid['name']} (AOTC).";
                    }
                    if ($diff['type'] === 'MYTHIC' && $row['killed'] > 0 && $row['killed'] < $row['total']) {
                        $remaining = $row['total'] - $row['killed'];
                        $out[] = "{$row['team_label']} team has {$remaining}/{$row['total']} Mythic boss"
                            . ($remaining === 1 ? '' : 'es') . " left to Cutting Edge in {$activeRaid['name']}.";
                    }
                }
            }
        }

        return $out;
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

    /**
     * AOTC / CE gap analysis for the latest tier, rolled up by alt
     * cohort. Pulls the latest Blizzard raid snapshot and the active
     * roster, then defers to AotcCohortGapBuilder for the actual
     * cohort math (kept pure for unit testing).
     *
     * Returns null when there's no equipment data yet at all (fresh
     * deploy, importer never ran), so the widget can render an empty
     * state instead of an error.
     *
     * @return ?array{
     *   tier: array{expansion_id:int, expansion_name:string, instance_id:int, instance_name:string},
     *   active_count: int,
     *   active_member_count: int,
     *   has_aotc: list<array{name:string, class:?string, alts:list<string>}>,
     *   missing_aotc: list<array{name:string, class:?string, alts:list<string>}>,
     *   has_ce: list<array{name:string, class:?string, alts:list<string>}>,
     *   captured_at: ?\Carbon\CarbonInterface,
     * }
     */
    private function aotcGap(string $guildKey): ?array
    {
        $latest = Snapshot::query()
            ->where('guild_key', $guildKey)
            ->where('source', Snapshot::SOURCE_BLIZZARD_RAIDS)
            ->latest('captured_at')
            ->first();
        if (! $latest) {
            return null;
        }

        $members = Member::query()
            ->forGuild($guildKey)
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'class', 'alt_group_id', 'main_member_id']);
        if ($members->isEmpty()) {
            return null;
        }

        $rows = MemberRaidSnapshot::query()
            ->where('snapshot_id', $latest->id)
            ->whereIn('member_id', $members->pluck('id')->all())
            ->get();
        if ($rows->isEmpty()) {
            return null;
        }

        $built = (new AotcCohortGapBuilder(new RaidProgressionAnalyzer()))->build($members, $rows);
        if ($built === null) {
            return null;
        }

        $built['captured_at'] = $latest->captured_at;

        return $built;
    }
}
