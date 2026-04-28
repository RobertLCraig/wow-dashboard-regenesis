<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AttendanceStat;
use App\Models\Member;
use App\Models\MemberAction;
use App\Models\MemberEvent;
use App\Models\MemberMplusRun;
use App\Models\MemberSnapshot;
use App\Models\Snapshot;
use App\Models\WclActorParse;
use App\Services\Bis\BisComparisonService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Single-character drilldown. Aggregates every data source we hold for
 * one member: latest GRM/RIO/wowaudit snapshot, recent WCL parses,
 * recent member_events from the GRM differ, action-queue history,
 * and the alt cohort.
 *
 * Pure read-only - all writes happen via existing per-source flows.
 */
class CharacterController extends Controller
{
    public function show(string $nameRealm, BisComparisonService $bis): View
    {
        abort_unless(auth()->user()?->can('roster.view'), 403);

        $guildKey = (string) config('grm.guild_key');

        $member = Member::query()
            ->forGuild($guildKey)
            ->where('name', $nameRealm)
            ->with(['main:id,name', 'altGroup'])
            ->firstOrFail();

        $latestSnapshots = $this->latestSnapshotPerSource($guildKey, $member->id);
        $recentParses = $this->recentParses($member->id, limit: 10);
        $recentEvents = $this->recentMemberEvents($member->id, limit: 20);
        $actionHistory = $this->recentActions($member->id, limit: 10);
        $altCohort = $this->altCohort($guildKey, $member);
        $attendance = $this->attendanceFor($guildKey, $member->name);
        $mplusActivity = $this->mplusActivity($member->id);
        // BiS comparison is a nice-to-have - a 500 here shouldn't take
        // out the rest of the page (the snapshot cards / parses /
        // attendance / alts are the load-bearing content). On error log
        // with member context so we can debug from laravel.log without
        // losing the page render. Service is container-resolved so
        // tests can swap in a stub.
        try {
            $bisComparison = $bis->compareForMember($member);
        } catch (\Throwable $e) {
            Log::warning('BiS comparison threw on character page', [
                'member' => $member->name,
                'class' => $member->class,
                'level' => $member->level,
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
            $bisComparison = null;
        }

        return view('dashboard.character.show', [
            'member' => $member,
            'latestSnapshots' => $latestSnapshots,
            'recentParses' => $recentParses,
            'recentEvents' => $recentEvents,
            'actionHistory' => $actionHistory,
            'altCohort' => $altCohort,
            'attendance' => $attendance,
            'bisComparison' => $bisComparison,
            'mplusActivity' => $mplusActivity,
        ]);
    }

    /**
     * Bundle every M+ data shape the character page panel needs in one
     * pass over member_mplus_runs:
     *
     *   - summary: counts + highest level for trailing 7/30/90 day windows
     *   - heatmap: cell-per-day for the last 13 weeks (today included),
     *              each carrying the run count and highest level for that
     *              calendar day in the user's local TZ
     *   - by_dungeon: counts + highest per dungeon over 30d, sorted by
     *                 count desc (drives the "are they farming one?" view)
     *   - recent: last 25 rows verbatim, newest first, for the table
     *
     * The query pulls everything within the largest window (90 days) once
     * and partitions in PHP - cheaper than three round trips for the
     * typical "<200 runs per character per quarter" volume.
     *
     * @return array{
     *   summary: array<string,array{count:int,highest:?int,timed:int}>,
     *   heatmap: array{from:CarbonImmutable, to:CarbonImmutable, days:array<string,array{count:int,highest:int,timed:int}>},
     *   by_dungeon: list<array{dungeon:string,short:?string,count:int,highest:int,timed:int}>,
     *   recent: Collection<int,MemberMplusRun>,
     * }
     */
    private function mplusActivity(int $memberId): array
    {
        $now = CarbonImmutable::now();
        $from = $now->subDays(90)->startOfDay();

        $runs = MemberMplusRun::query()
            ->where('member_id', $memberId)
            ->where('completed_at', '>=', $from)
            ->orderByDesc('completed_at')
            ->get();

        $summary = [
            '7d' => $this->summariseWindow($runs, $now->subDays(7)),
            '30d' => $this->summariseWindow($runs, $now->subDays(30)),
            '90d' => $this->summariseWindow($runs, $now->subDays(90)),
        ];

        $heatmap = $this->heatmapBuckets($runs, weeks: 13, now: $now);
        $byDungeon = $this->dungeonBreakdown($runs->where('completed_at', '>=', $now->subDays(30)));

        // Exclude any runs from older than 365 days from "recent" so the
        // table doesn't drift into prior-season noise. The list is ordered
        // by completed_at desc already.
        $recent = $runs->take(25);

        return [
            'summary' => $summary,
            'heatmap' => $heatmap,
            'by_dungeon' => $byDungeon,
            'recent' => $recent,
        ];
    }

    /**
     * @param  Collection<int,MemberMplusRun>  $runs
     * @return array{count:int,highest:?int,timed:int}
     */
    private function summariseWindow(Collection $runs, CarbonImmutable $cutoff): array
    {
        $window = $runs->filter(fn (MemberMplusRun $r) => $r->completed_at->greaterThanOrEqualTo($cutoff));
        return [
            'count' => $window->count(),
            'highest' => $window->max('mythic_level'),
            'timed' => $window->filter(fn (MemberMplusRun $r) => $r->isTimed())->count(),
        ];
    }

    /**
     * Bucket runs into per-day cells for the heatmap. Returns an
     * associative array keyed by Y-m-d so the view can render a fixed
     * grid even for days with zero activity.
     *
     * @param  Collection<int,MemberMplusRun>  $runs
     * @return array{from:CarbonImmutable, to:CarbonImmutable, days:array<string,array{count:int,highest:int,timed:int}>}
     */
    private function heatmapBuckets(Collection $runs, int $weeks, CarbonImmutable $now): array
    {
        // Anchor the grid to a Monday so a week column is a real raid week.
        $endOfWeek = $now->endOfDay();
        $startOfWeek = $now->startOfWeek(Carbon::MONDAY)->subWeeks($weeks - 1)->startOfDay();

        $days = [];
        $cursor = $startOfWeek;
        while ($cursor->lessThanOrEqualTo($endOfWeek)) {
            $days[$cursor->format('Y-m-d')] = ['count' => 0, 'highest' => 0, 'timed' => 0];
            $cursor = $cursor->addDay();
        }

        foreach ($runs as $r) {
            $key = $r->completed_at->format('Y-m-d');
            if (! isset($days[$key])) {
                continue;
            }
            $days[$key]['count']++;
            $days[$key]['highest'] = max($days[$key]['highest'], $r->mythic_level);
            if ($r->isTimed()) {
                $days[$key]['timed']++;
            }
        }

        return [
            'from' => $startOfWeek,
            'to' => $endOfWeek,
            'days' => $days,
        ];
    }

    /**
     * @param  Collection<int,MemberMplusRun>  $runs
     * @return list<array{dungeon:string,short:?string,count:int,highest:int,timed:int}>
     */
    private function dungeonBreakdown(Collection $runs): array
    {
        $byKey = [];
        foreach ($runs as $r) {
            $key = $r->dungeon_short_name ?? $r->dungeon_name ?? 'unknown';
            if (! isset($byKey[$key])) {
                $byKey[$key] = [
                    'dungeon' => $r->dungeon_name ?? $key,
                    'short' => $r->dungeon_short_name,
                    'count' => 0,
                    'highest' => 0,
                    'timed' => 0,
                ];
            }
            $byKey[$key]['count']++;
            $byKey[$key]['highest'] = max($byKey[$key]['highest'], $r->mythic_level);
            if ($r->isTimed()) {
                $byKey[$key]['timed']++;
            }
        }
        usort($byKey, fn (array $a, array $b) => $b['count'] <=> $a['count']);
        return array_values($byKey);
    }

    /**
     * @return array<string, array{snapshot: Snapshot, member_snapshot: MemberSnapshot}>
     */
    private function latestSnapshotPerSource(string $guildKey, int $memberId): array
    {
        $out = [];
        foreach ([Snapshot::SOURCE_GRM, Snapshot::SOURCE_RAIDERIO, Snapshot::SOURCE_WOWAUDIT] as $source) {
            $latest = MemberSnapshot::query()
                ->whereHas('snapshot', fn ($q) => $q->where('guild_key', $guildKey)->where('source', $source))
                ->where('member_id', $memberId)
                ->with('snapshot:id,source,captured_at')
                ->orderByDesc(
                    Snapshot::query()->select('captured_at')->whereColumn('snapshots.id', 'member_snapshots.snapshot_id')
                )
                ->first();
            if ($latest) {
                $out[$source] = ['snapshot' => $latest->snapshot, 'member_snapshot' => $latest];
            }
        }
        return $out;
    }

    /**
     * @return Collection<int, WclActorParse>
     */
    private function recentParses(int $memberId, int $limit): Collection
    {
        return WclActorParse::query()
            ->where('member_id', $memberId)
            ->with(['fight:id,wcl_report_id,fight_id,name,difficulty,kill,best_percentage,start_time', 'fight.report:id,code,title'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, MemberEvent>
     */
    private function recentMemberEvents(int $memberId, int $limit): Collection
    {
        return MemberEvent::query()
            ->where('member_id', $memberId)
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, MemberAction>
     */
    private function recentActions(int $memberId, int $limit): Collection
    {
        return MemberAction::query()
            ->where('member_id', $memberId)
            ->with('reviewedBy:id,discord_username')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Other characters in the same alt group, including the main if
     * this row is an alt. Empty when the member is a singleton.
     *
     * @return Collection<int, Member>
     */
    private function altCohort(string $guildKey, Member $member): Collection
    {
        if (! $member->alt_group_id) {
            return collect();
        }
        return Member::query()
            ->forGuild($guildKey)
            ->where('alt_group_id', $member->alt_group_id)
            ->where('id', '!=', $member->id)
            ->orderBy('name')
            ->get(['id', 'name', 'class', 'level', 'main_member_id']);
    }

    /**
     * Latest attendance row for this character. attendance_stats is
     * keyed by member_name (mirrors the SyncRaidHelperAttendance writer
     * which only ever has a Discord user-name + guild scope, never our
     * local Member.id), so we look up by name. SQLite is lenient about
     * non-existent columns and returns no rows; MySQL errors with
     * SQLSTATE 42S22 when the column is missing - which is what bit us
     * in production when the controller previously queried `member_id`.
     */
    private function attendanceFor(string $guildKey, string $memberName): ?AttendanceStat
    {
        return AttendanceStat::query()
            ->where('guild_key', $guildKey)
            ->where('member_name', $memberName)
            ->latest('captured_at')
            ->first();
    }
}
