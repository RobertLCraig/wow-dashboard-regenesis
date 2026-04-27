<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AttendanceStat;
use App\Models\Member;
use App\Models\MemberAction;
use App\Models\MemberEvent;
use App\Models\MemberSnapshot;
use App\Models\Snapshot;
use App\Models\WclActorParse;
use App\Services\Bis\BisComparisonService;
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
        $attendance = $this->attendanceFor($guildKey, $member->id);
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
        ]);
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
     * @return ?AttendanceStat
     */
    private function attendanceFor(string $guildKey, int $memberId): ?AttendanceStat
    {
        return AttendanceStat::query()
            ->where('guild_key', $guildKey)
            ->where('member_id', $memberId)
            ->latest('captured_at')
            ->first();
    }
}
