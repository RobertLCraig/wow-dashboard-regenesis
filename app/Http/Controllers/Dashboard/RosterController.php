<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberSnapshot;
use App\Models\Snapshot;
use App\Models\TeamMapping;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

/**
 * Searchable + filterable roster page. Replaces the alt-groups +
 * recently-inactive widgets on the General dashboard with a single
 * surface that officers can slice quickly.
 *
 * Filter chips drive the `?filter=` query param and apply atomically -
 * one filter at a time keeps the URL shareable and the UI legible.
 * Search runs client-side via the existing sortableTable() Alpine
 * factory in the layout, so this controller just hands over rows.
 */
class RosterController extends Controller
{
    /** Allowed values for the ?filter= query string. */
    private const FILTERS = [
        'all', 'inactive_7d', 'inactive_14d', 'inactive_30d', 'inactive_60d', 'inactive_90d',
        'alts', 'mains', 'trial', 'action_queue', 'banned',
    ];

    public function index(Request $request): View
    {
        abort_unless(auth()->user()?->can('roster.view'), 403);

        $filter = $this->normaliseFilter($request->query('filter'));
        $rows = $this->rows($filter);

        return view('dashboard.roster', [
            'rows' => $rows,
            'filter' => $filter,
            'counts' => $this->chipCounts(),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        abort_unless(auth()->user()?->can('roster.view'), 403);

        $filter = $this->normaliseFilter($request->query('filter'));
        $rows = $this->rows($filter);
        $filename = 'roster-' . now()->format('Y-m-d') . ($filter !== 'all' ? "-{$filter}" : '') . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'wb');
            fputcsv($out, [
                'name', 'realm', 'class', 'level', 'rank', 'team',
                'ilvl', 'mplus_score', 'mplus_keystone',
                'last_online_at', 'main', 'flags',
            ]);
            foreach ($rows as $row) {
                $m = $row['member'];
                $snap = $row['snap'];
                fputcsv($out, [
                    $m->name,
                    $m->realm,
                    $m->class,
                    $m->level,
                    $m->rank_name,
                    $m->team,
                    $snap?->ilvl,
                    $snap?->mplus_score,
                    $snap?->mplus_keystone,
                    $m->last_online_at?->toIso8601String(),
                    $row['main']?->name,
                    implode('|', $row['flags']),
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function normaliseFilter(?string $filter): string
    {
        return in_array($filter, self::FILTERS, true) ? $filter : 'all';
    }

    /**
     * @return Collection<int, array{member: Member, snap: ?MemberSnapshot, main: ?Member, flags: list<string>}>
     */
    private function rows(string $filter): Collection
    {
        $guildKey = (string) config('grm.guild_key');

        $members = $this->baseQuery($guildKey, $filter)
            ->with('main:id,name')
            ->orderBy('name')
            ->get();

        $snapsByMember = $this->latestRaiderioSnapshotsByMember($guildKey, $members);

        return $members->map(function (Member $m) use ($snapsByMember) {
            return [
                'member' => $m,
                'snap' => $snapsByMember->get($m->id),
                'main' => $m->main,
                'flags' => $this->flagsFor($m),
            ];
        })->values();
    }

    private function baseQuery(string $guildKey, string $filter): Builder
    {
        $q = Member::query()->forGuild($guildKey);

        // 'banned' is the only filter that surfaces non-active members.
        // Everything else implicitly scopes to active.
        if ($filter !== 'banned') {
            $q->active();
        }

        return match ($filter) {
            'inactive_7d'  => $this->inactive($q, 7),
            'inactive_14d' => $this->inactive($q, 14),
            'inactive_30d' => $this->inactive($q, 30),
            'inactive_60d' => $this->inactive($q, 60),
            'inactive_90d' => $this->inactive($q, 90),
            'alts'    => $q->whereNotNull('main_member_id'),
            'mains'   => $q->whereNull('main_member_id')->whereNotNull('alt_group_id'),
            'trial'   => $q->whereIn('team', [TeamMapping::TEAM_HEROIC_TRIAL, TeamMapping::TEAM_MYTHIC_TRIAL]),
            'action_queue' => $q->where(function (Builder $sub) {
                $sub->where('recommend_promote', true)
                    ->orWhere('recommend_demote', true)
                    ->orWhere('recommend_kick', true);
            }),
            'banned'  => $q->where('status', Member::STATUS_BANNED),
            default   => $q,
        };
    }

    private function inactive(Builder $q, int $days): Builder
    {
        return $q->whereNotNull('last_online_at')
            ->where('last_online_at', '<', Carbon::now()->subDays($days));
    }

    /**
     * Pluck the latest raiderio snapshot once for the visible member set
     * to avoid an N+1.
     *
     * @param  EloquentCollection<int, Member>  $members
     * @return Collection<int, MemberSnapshot>
     */
    private function latestRaiderioSnapshotsByMember(string $guildKey, EloquentCollection $members): Collection
    {
        if ($members->isEmpty()) {
            return collect();
        }
        $latest = Snapshot::query()
            ->where('guild_key', $guildKey)
            ->where('source', Snapshot::SOURCE_RAIDERIO)
            ->latest('captured_at')
            ->first();
        if (! $latest) {
            return collect();
        }
        return MemberSnapshot::query()
            ->where('snapshot_id', $latest->id)
            ->whereIn('member_id', $members->pluck('id'))
            ->get()
            ->keyBy('member_id');
    }

    /**
     * @return list<string>
     */
    private function flagsFor(Member $m): array
    {
        $flags = [];
        if ($m->recommend_promote) $flags[] = 'promote';
        if ($m->recommend_demote)  $flags[] = 'demote';
        if ($m->recommend_kick)    $flags[] = 'kick';
        if ($m->recommend_special) $flags[] = 'special';
        if ($m->status === Member::STATUS_BANNED) $flags[] = 'banned';
        return $flags;
    }

    /**
     * One COUNT() per chip so officers can see at a glance "30 inactive
     * 30d, 4 in action queue". Cheap because each filter is a single
     * indexed query.
     *
     * @return array<string,int>
     */
    private function chipCounts(): array
    {
        $guildKey = (string) config('grm.guild_key');
        $counts = [];
        foreach (self::FILTERS as $f) {
            $counts[$f] = $this->baseQuery($guildKey, $f)->count();
        }
        return $counts;
    }
}
