<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\BisProfile;
use App\Models\Member;
use App\Models\MemberEquipmentSnapshot;
use App\Models\MemberMplusRun;
use App\Models\MemberSnapshot;
use App\Models\Snapshot;
use App\Models\TeamMapping;
use App\Services\Bis\BisComparisonService;
use App\Services\Blizzard\EquipmentAnalyzer;
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
        'alts', 'mains', 'trial', 'action_queue', 'bis_issues', 'banned',
        'no_keys_14d', 'no_keys_30d',
    ];

    public function index(Request $request): View
    {
        abort_unless(auth()->user()?->can('roster.view'), 403);

        $filter = $this->normaliseFilter($request->query('filter'));
        $grouped = $this->normaliseGrouped($request->query('group'));
        $rows = $this->rows($filter, $grouped);

        return view('dashboard.roster', [
            'rows' => $rows,
            'filter' => $filter,
            'grouped' => $grouped,
            'counts' => $this->chipCounts(),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        abort_unless(auth()->user()?->can('roster.view'), 403);

        $filter = $this->normaliseFilter($request->query('filter'));
        // CSV always exports flat: officers want one row per character,
        // not collapsed cohorts. The ?group= flag is ignored here.
        $rows = $this->rows($filter, false);
        $filename = 'roster-' . now()->format('Y-m-d') . ($filter !== 'all' ? "-{$filter}" : '') . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'wb');
            fputcsv($out, [
                'name', 'realm', 'class', 'level', 'rank', 'team',
                'ilvl', 'ilvl_source', 'mplus_score', 'mplus_keystone',
                'keys_30d', 'keys_30d_highest', 'keys_30d_last_completed',
                'bis_issues_total', 'bis_missing_enchants', 'bis_missing_gems',
                'gear_health_total', 'gear_missing_enchants', 'gear_empty_sockets',
                'last_online_at', 'main', 'flags',
            ]);
            foreach ($rows as $row) {
                $m = $row['member'];
                $snap = $row['snap'];
                $bis = $row['bis_issues'];
                $gh = $row['gear_health'];
                $act = $row['mplus_activity'] ?? null;
                fputcsv($out, [
                    $m->name,
                    $m->realm,
                    $m->class,
                    $m->level,
                    $m->rank_name,
                    implode('|', $m->teamValues()),
                    $row['ilvl'],
                    $row['ilvl_source'],
                    $snap?->mplus_score,
                    $snap?->mplus_keystone,
                    $act['count'] ?? 0,
                    $act['highest'] ?? null,
                    ($act['last_completed_at'] ?? null)?->toIso8601String(),
                    $bis['total'] ?? null,
                    $bis['missing_enchants'] ?? null,
                    $bis['missing_gems'] ?? null,
                    $gh['total_issues'] ?? null,
                    $gh ? count($gh['missing_enchants']) : null,
                    $gh ? count($gh['empty_sockets']) : null,
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

    private function normaliseGrouped(mixed $raw): bool
    {
        return in_array($raw, ['1', 1, true, 'true', 'on'], true);
    }

    /**
     * @return Collection<int, array{member: Member, snap: ?MemberSnapshot, ilvl: ?int, ilvl_source: ?string, bis_issues: ?array<string,int>, gear_health: ?array{missing_enchants:list<string>, empty_sockets:list<string>, total_issues:int, equipped_ilvl:?int, pieces_count:int}, main: ?Member, flags: list<string>, group_member_ids: list<int>, alts: \Illuminate\Support\Collection<int,Member>}>
     */
    private function rows(string $filter, bool $grouped): Collection
    {
        $guildKey = (string) config('grm.guild_key');

        $members = $this->baseQuery($guildKey, $filter)
            ->with(['main:id,name', 'teams'])
            ->orderBy('name')
            ->get();

        $snapsByMember = $this->latestRaiderioSnapshotsByMember($guildKey, $members);
        $ilvlsByMember = $this->resolveIlvls($guildKey, $members);
        $groupIdsByMember = $this->altGroupIdsByMember($guildKey, $members);
        $bisIssuesByMember = $this->bisIssuesByMember($members, $snapsByMember);
        $gearHealthByMember = $this->gearHealthByMember($guildKey, $members);
        $mplusActivityByMember = $this->mplusActivityByMember($members, days: 30);
        $staleMainByMember = $this->mainLooksStaleByMember($guildKey, $members);
        $altGroupSiblings = $this->altGroupSiblingNames($guildKey, $members);

        // 'bis_issues' filter is post-comparison: baseQuery returns all
        // active members, then we keep only those with total > 0.
        if ($filter === 'bis_issues') {
            $members = $members->filter(fn (Member $m) => ($bisIssuesByMember->get($m->id)['total'] ?? 0) > 0)->values();
        }

        // Grouped mode: alts whose main is also visible get folded into
        // the main row's `alts` list. Alts whose main is filtered out
        // appear as their own row (orphans) so they're never invisible.
        $visibleIds = $members->pluck('id')->all();
        $altsByMainId = $grouped
            ? $this->altsForMains($guildKey, $members->whereNull('main_member_id')->pluck('id')->all())
            : collect();

        $rowMembers = $grouped
            ? $members->filter(fn (Member $m) => $m->main_member_id === null
                || ! in_array($m->main_member_id, $visibleIds, true))->values()
            : $members;

        return $rowMembers->map(function (Member $m) use ($snapsByMember, $ilvlsByMember, $bisIssuesByMember, $gearHealthByMember, $groupIdsByMember, $altsByMainId, $staleMainByMember, $mplusActivityByMember, $altGroupSiblings) {
            $ilvl = $ilvlsByMember->get($m->id, ['ilvl' => null, 'source' => null]);
            return [
                'member' => $m,
                'snap' => $snapsByMember->get($m->id),
                'ilvl' => $ilvl['ilvl'],
                'ilvl_source' => $ilvl['source'],
                'bis_issues' => $bisIssuesByMember->get($m->id),
                'gear_health' => $gearHealthByMember->get($m->id),
                'mplus_activity' => $mplusActivityByMember->get($m->id),
                'main' => $m->main,
                'flags' => $this->flagsFor($m, (bool) $staleMainByMember->get($m->id, false)),
                // Other character names sharing this member's alt_group_id,
                // used by the view's name-diff highlighter to draw attention
                // to diacritics that differ between near-identical names.
                'siblings' => $altGroupSiblings->get($m->id, []),
                // Full kick-and-alts cohort for this row: main + all alts
                // in the same alt_group. Singletons just contain the row's
                // own id. Used by the kick-macro modal as data-member-ids.
                'group_member_ids' => $groupIdsByMember->get($m->id, [$m->id]),
                // Populated only in grouped mode for rows that are mains
                // with at least one alt. The view renders these as an
                // expandable sub-list under the main's name cell.
                'alts' => $altsByMainId->get($m->id, collect()),
            ];
        })->values();
    }

    /**
     * Bulk BiS issue counts. One BisProfile load up front (all rows,
     * including hero-talent variants), then a pure in-memory comparison
     * per member - the service picks whichever variant best matches
     * the player's actual gear. Missing entries mean "no data, render -".
     *
     * @param  EloquentCollection<int, Member>  $members
     * @param  Collection<int, MemberSnapshot>  $snapsByMember
     * @return Collection<int, array{missing_enchants:int, wrong_enchants:int, missing_gems:int, wrong_gems:int, total:int}>
     */
    private function bisIssuesByMember(EloquentCollection $members, Collection $snapsByMember): Collection
    {
        if ($members->isEmpty()) {
            return collect();
        }

        // Group all profiles by class|spec so the service can pick the
        // best-matching variant for each member without an N+1.
        $profilesByClassSpec = BisProfile::query()
            ->get()
            ->groupBy(fn (BisProfile $p) => $p->class . '|' . $p->spec);
        if ($profilesByClassSpec->isEmpty()) {
            return collect();
        }

        $service = new BisComparisonService();
        $out = collect();
        foreach ($members as $member) {
            $snap = $snapsByMember->get($member->id);
            if ($snap === null) {
                continue;
            }
            $raw = $service->rawArray($snap);
            if ($raw === null) {
                continue;
            }
            $class = $service->classKey($member);
            $spec = $service->normaliseSpec($raw['active_spec_name'] ?? null);
            if ($class === null || $spec === null) {
                continue;
            }
            $candidates = $profilesByClassSpec->get($class . '|' . $spec, collect());
            $profile = $service->pickBestProfile($candidates, $raw);
            if ($profile === null) {
                continue;
            }
            $comparison = $service->compareWithData($member, $raw, $profile);
            $out->put($member->id, $service->countIssues($comparison));
        }
        return $out;
    }

    /**
     * Latest Blizzard equipment snapshot per member, run through
     * EquipmentAnalyzer for the "ready for raid invite" lens. Pure
     * universal rules (no SimC profile dependency) so this complements
     * rather than duplicates the BiS-comparison data: officers get a
     * raw "did this character forget an enchant" signal that works for
     * trials, fresh alts, and classes without a profile loaded.
     *
     * @param  EloquentCollection<int, Member>  $members
     * @return Collection<int, array{missing_enchants:list<string>, empty_sockets:list<string>, total_issues:int, equipped_ilvl:?int, pieces_count:int}>
     */
    private function gearHealthByMember(string $guildKey, EloquentCollection $members): Collection
    {
        if ($members->isEmpty()) {
            return collect();
        }

        $latest = Snapshot::query()
            ->where('guild_key', $guildKey)
            ->where('source', Snapshot::SOURCE_BLIZZARD_EQUIPMENT)
            ->latest('captured_at')
            ->first();
        if (! $latest) {
            return collect();
        }

        $rows = MemberEquipmentSnapshot::query()
            ->where('snapshot_id', $latest->id)
            ->whereIn('member_id', $members->pluck('id')->all())
            ->get();

        $analyzer = new EquipmentAnalyzer();
        $out = collect();
        foreach ($rows as $row) {
            $out->put($row->member_id, $analyzer->analyze($row));
        }
        return $out;
    }

    /**
     * Multi-source ilvl resolver. Walks Blizzard -> Wowaudit -> RIO and
     * for each member takes the first non-null ilvl. Blizzard wins
     * because it's the authoritative source (updates within minutes of
     * logout); Wowaudit beats RIO for the mythic team's tracked chars
     * because it refreshes hourly with no scrape-cache delay; RIO is the
     * roster-wide fallback.
     *
     * Returns one entry per member that has an ilvl in any source.
     * Members missing from all three return null at the call site.
     *
     * @param  EloquentCollection<int, Member>  $members
     * @return Collection<int, array{ilvl: int, source: string}>
     */
    private function resolveIlvls(string $guildKey, EloquentCollection $members): Collection
    {
        if ($members->isEmpty()) {
            return collect();
        }
        $memberIds = $members->pluck('id')->all();

        $resolved = collect();
        foreach ([Snapshot::SOURCE_BLIZZARD, Snapshot::SOURCE_WOWAUDIT, Snapshot::SOURCE_RAIDERIO] as $source) {
            $latest = Snapshot::query()
                ->where('guild_key', $guildKey)
                ->where('source', $source)
                ->latest('captured_at')
                ->first();
            if (! $latest) {
                continue;
            }

            $rows = MemberSnapshot::query()
                ->where('snapshot_id', $latest->id)
                ->whereIn('member_id', $memberIds)
                ->whereNotNull('ilvl')
                ->get(['member_id', 'ilvl']);

            foreach ($rows as $row) {
                // Higher-priority source already resolved this member.
                if ($resolved->has($row->member_id)) {
                    continue;
                }
                $resolved->put($row->member_id, [
                    'ilvl' => (int) $row->ilvl,
                    'source' => $source,
                ]);
            }
        }
        return $resolved;
    }

    /**
     * For grouped mode: pull every alt of every visible main in one
     * query and key by main_member_id. Includes alts that wouldn't
     * pass the current filter, so expanding a main always shows their
     * full cohort (matches the old alt-groups widget behaviour).
     *
     * @param  list<int>  $mainIds
     * @return Collection<int, \Illuminate\Database\Eloquent\Collection<int, Member>>
     */
    private function altsForMains(string $guildKey, array $mainIds): Collection
    {
        if ($mainIds === []) {
            return collect();
        }

        return Member::query()
            ->forGuild($guildKey)
            ->active()
            ->whereIn('main_member_id', $mainIds)
            ->orderBy('name')
            ->get()
            ->groupBy('main_member_id');
    }

    /**
     * For each visible member, compute the full set of member IDs that
     * make up "kick this player and all their alts". For a row with no
     * alt_group, that's just the row itself. For a row whose alt_group
     * is shared, it's every active member in the same group.
     *
     * @param  EloquentCollection<int, Member>  $members
     * @return Collection<int, list<int>>
     */
    private function altGroupIdsByMember(string $guildKey, EloquentCollection $members): Collection
    {
        $groupIds = $members->pluck('alt_group_id')->filter()->unique()->values();
        if ($groupIds->isEmpty()) {
            return collect();
        }
        // One query for every member in any of the visible alt groups.
        $bucketRows = Member::query()
            ->forGuild($guildKey)
            ->active()
            ->whereIn('alt_group_id', $groupIds)
            ->get(['id', 'alt_group_id']);

        $byGroup = $bucketRows->groupBy('alt_group_id')
            ->map(fn ($group) => $group->pluck('id')->all());

        return $members->mapWithKeys(function (Member $m) use ($byGroup) {
            $ids = $m->alt_group_id ? ($byGroup->get($m->alt_group_id, []) ?: [$m->id]) : [$m->id];
            return [$m->id => $ids];
        });
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
            'trial'   => $q->onAnyTeam([TeamMapping::TEAM_HEROIC_TRIAL, TeamMapping::TEAM_MYTHIC_TRIAL]),
            'action_queue' => $q->where(function (Builder $sub) {
                $sub->where('recommend_promote', true)
                    ->orWhere('recommend_demote', true)
                    ->orWhere('recommend_kick', true);
            }),
            'banned'  => $q->where('status', Member::STATUS_BANNED),
            'no_keys_14d' => $this->withoutKeysSince($q, 14),
            'no_keys_30d' => $this->withoutKeysSince($q, 30),
            default   => $q,
        };
    }

    /**
     * Members with zero M+ runs completed in the last N days. The "did
     * Joe run anything?" filter that drove this whole feature: a raid
     * leader picks 14d or 30d and gets the inactivity list. Trial chars
     * appear here too (no carve-out) because that's exactly the cohort
     * an officer wants to flag.
     */
    private function withoutKeysSince(Builder $q, int $days): Builder
    {
        $cutoff = Carbon::now()->subDays($days);
        return $q->whereNotIn('id', MemberMplusRun::query()
            ->select('member_id')
            ->where('completed_at', '>=', $cutoff));
    }

    private function inactive(Builder $q, int $days): Builder
    {
        return $q->whereNotNull('last_online_at')
            ->where('last_online_at', '<', Carbon::now()->subDays($days));
    }

    /**
     * Recent M+ activity per member: count of timed/untimed runs in the
     * trailing window plus the highest level seen and the most recent
     * completion. Used by the roster "Keys Nd" column and the no_keys_*
     * filter chips.
     *
     * One aggregate query, keyed by member_id. Members with zero runs
     * in the window do not appear in the result; the view treats a
     * missing entry as "no keys".
     *
     * @param  EloquentCollection<int, Member>  $members
     * @return Collection<int, array{count:int, highest:int, last_completed_at:\Carbon\CarbonInterface}>
     */
    private function mplusActivityByMember(EloquentCollection $members, int $days): Collection
    {
        if ($members->isEmpty()) {
            return collect();
        }
        $cutoff = Carbon::now()->subDays($days);

        $rows = MemberMplusRun::query()
            ->whereIn('member_id', $members->pluck('id')->all())
            ->where('completed_at', '>=', $cutoff)
            ->selectRaw('member_id, count(*) as run_count, max(mythic_level) as highest, max(completed_at) as last_completed_at')
            ->groupBy('member_id')
            ->get();

        return $rows->mapWithKeys(fn ($r) => [(int) $r->member_id => [
            'count' => (int) $r->run_count,
            'highest' => (int) $r->highest,
            'last_completed_at' => Carbon::parse((string) $r->last_completed_at),
        ]]);
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
    private function flagsFor(Member $m, bool $mainLooksStale = false): array
    {
        $flags = [];
        if ($m->recommend_promote) $flags[] = 'promote';
        if ($m->recommend_demote)  $flags[] = 'demote';
        if ($m->recommend_kick)    $flags[] = 'kick';
        if ($m->recommend_special) $flags[] = 'special';
        if ($m->status === Member::STATUS_BANNED) $flags[] = 'banned';
        if ($mainLooksStale) $flags[] = 'main?';
        return $flags;
    }

    /**
     * For each visible member, list the other character names sharing
     * its alt_group_id. The view feeds this into NameDiff to highlight
     * the diacritics that distinguish near-identical sibling names.
     *
     * @param  EloquentCollection<int, Member>  $members
     * @return Collection<int, list<string>>
     */
    private function altGroupSiblingNames(string $guildKey, EloquentCollection $members): Collection
    {
        if ($members->isEmpty()) {
            return collect();
        }
        $groupIds = $members->pluck('alt_group_id')->filter()->unique()->values();
        if ($groupIds->isEmpty()) {
            return collect();
        }
        $bucket = Member::query()
            ->forGuild($guildKey)
            ->active()
            ->whereIn('alt_group_id', $groupIds)
            ->get(['id', 'name', 'alt_group_id'])
            ->groupBy('alt_group_id');

        return $members->mapWithKeys(function (Member $m) use ($bucket) {
            if ($m->alt_group_id === null) {
                return [$m->id => []];
            }
            $names = $bucket->get($m->alt_group_id, collect())
                ->where('id', '!=', $m->id)
                ->pluck('name')
                ->all();
            return [$m->id => $names];
        });
    }

    /**
     * "Main looks stale" heuristic: the row is the head of an alt group
     * (main_member_id IS NULL but alt_group_id is set) AND at least one
     * of its alts has logged in 14+ days more recently than the main.
     *
     * Threshold is 14 days because shorter windows trip on legitimate
     * patterns (raid-only main + farming alt during a quiet patch week).
     * Two raid weeks of consistent alt-only logins is the point where a
     * silent main switch becomes worth surfacing.
     *
     * @param  EloquentCollection<int, Member>  $members
     * @return Collection<int, bool>
     */
    private function mainLooksStaleByMember(string $guildKey, EloquentCollection $members): Collection
    {
        if ($members->isEmpty()) {
            return collect();
        }
        $mainIds = $members
            ->filter(fn (Member $m) => $m->main_member_id === null && $m->alt_group_id !== null && $m->last_online_at !== null)
            ->pluck('id')
            ->all();
        if ($mainIds === []) {
            return collect();
        }

        $alts = Member::query()
            ->forGuild($guildKey)
            ->active()
            ->whereIn('main_member_id', $mainIds)
            ->whereNotNull('last_online_at')
            ->get(['main_member_id', 'last_online_at']);
        if ($alts->isEmpty()) {
            return collect();
        }

        $maxAltByMain = $alts->groupBy('main_member_id')
            ->map(fn ($group) => $group->max('last_online_at'));

        $stale = collect();
        foreach ($members as $main) {
            if ($main->main_member_id !== null || $main->alt_group_id === null || $main->last_online_at === null) {
                continue;
            }
            $maxAlt = $maxAltByMain->get($main->id);
            if ($maxAlt === null || $maxAlt->lessThanOrEqualTo($main->last_online_at)) {
                continue;
            }
            if ($main->last_online_at->diffInDays($maxAlt) >= 14) {
                $stale->put($main->id, true);
            }
        }
        return $stale;
    }

    /**
     * One COUNT() per chip so officers can see at a glance "30 inactive
     * 30d, 4 in action queue". Most are a single indexed query; the
     * bis_issues count needs an in-memory pass over comparisons since
     * issue detection is post-SQL.
     *
     * @return array<string,int>
     */
    private function chipCounts(): array
    {
        $guildKey = (string) config('grm.guild_key');
        $counts = [];
        foreach (self::FILTERS as $f) {
            if ($f === 'bis_issues') {
                $counts[$f] = $this->countBisIssueMembers($guildKey);
                continue;
            }
            $counts[$f] = $this->baseQuery($guildKey, $f)->count();
        }
        return $counts;
    }

    /**
     * Count of active members with at least one BiS issue. Bounded to
     * the active roster (typically ~250 with RIO data after the recency
     * gate); reuses the same in-memory comparison the rows() method
     * runs, but doesn't share its results because the chip count is
     * computed before rows() runs.
     */
    private function countBisIssueMembers(string $guildKey): int
    {
        $members = Member::query()->forGuild($guildKey)->active()->get();
        if ($members->isEmpty()) {
            return 0;
        }
        $snapsByMember = $this->latestRaiderioSnapshotsByMember($guildKey, $members);
        $issues = $this->bisIssuesByMember($members, $snapsByMember);
        return $issues->filter(fn (array $i) => ($i['total'] ?? 0) > 0)->count();
    }
}
