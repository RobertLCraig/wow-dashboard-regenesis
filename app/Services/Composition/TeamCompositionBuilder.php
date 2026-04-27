<?php

namespace App\Services\Composition;

use App\Models\Member;
use App\Models\WclActorParse;
use App\Models\WclFight;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Aggregate per-member parse data for a team into a comp-planner shape:
 *
 *   role => list of { member, parses_count, avg_parse, best_parse,
 *                     best_fight, latest_spec, latest_ilvl }
 *
 * Members without any parses in the window still appear (so a tank
 * who doesn't get parsed at all is visible to the raid lead) under
 * the role inferred from their most-recent recorded spec. If we have
 * never seen them in WCL, they fall under an 'unknown' bucket.
 */
class TeamCompositionBuilder
{
    /**
     * @param  Collection<int, Member>  $members
     * @param  list<int>|null  $difficulties  e.g. [WclFight::DIFFICULTY_HEROIC]; null = any
     * @return array<string, list<array{
     *     member: Member,
     *     parses_count: int,
     *     avg_parse: ?float,
     *     best_parse: ?int,
     *     best_fight: ?WclFight,
     *     latest_spec: ?string,
     *     latest_class: ?string,
     *     latest_ilvl: ?int,
     *     role: string,
     * }>>
     */
    public function build(Collection $members, int $days = 14, ?array $difficulties = null): array
    {
        if ($members->isEmpty()) {
            return [];
        }

        $since = CarbonImmutable::now()->subDays($days);
        $memberIds = $members->pluck('id')->all();

        $query = WclActorParse::query()
            ->whereIn('member_id', $memberIds)
            ->whereHas('fight', function ($q) use ($since, $difficulties) {
                $q->where('start_time', '>=', $since);
                if ($difficulties) {
                    $q->whereIn('difficulty', $difficulties);
                }
            })
            ->with(['fight:id,name,difficulty,kill,start_time'])
            ->orderByDesc('parse_percentile')
            ->orderByDesc('id');

        $parses = $query->get();

        // Group parses by member_id so we can compute aggregates per
        // member in a single pass.
        $byMember = $parses->groupBy('member_id');

        $rolesOrdered = SpecRoleMap::orderedRoles();
        $buckets = array_fill_keys($rolesOrdered, []);
        $buckets['unknown'] = [];

        foreach ($members as $member) {
            $memberParses = $byMember->get($member->id, collect());

            $latest = $memberParses->sortByDesc(fn ($p) => $p->fight?->start_time)->first();
            $latestSpec  = $latest?->actor_spec;
            $latestClass = $latest?->actor_class ?? $member->class;
            $latestIlvl  = $latest?->item_level;

            // Role priority: explicit spec map -> wcl role column -> unknown.
            $role = SpecRoleMap::role($latestClass, $latestSpec);
            if (! $role) {
                $role = $this->fallbackRole($latest?->role);
            }

            $ranked = $memberParses->filter(fn ($p) => $p->parse_percentile !== null);
            $best   = $ranked->first(); // already sorted parse_percentile desc
            $avg    = $ranked->isNotEmpty() ? $ranked->avg('parse_percentile') : null;

            $buckets[$role ?? 'unknown'][] = [
                'member'        => $member,
                'parses_count'  => $ranked->count(),
                'avg_parse'     => $avg !== null ? (float) round($avg, 1) : null,
                'best_parse'    => $best?->parse_percentile,
                'best_fight'    => $best?->fight,
                'latest_spec'   => $latestSpec,
                'latest_class'  => $latestClass,
                'latest_ilvl'   => $latestIlvl,
                'role'          => $role ?? 'unknown',
            ];
        }

        // Sort each bucket by avg_parse desc (members without ranked
        // parses fall to the bottom in name order).
        foreach ($buckets as $role => $rows) {
            usort($buckets[$role], function ($a, $b) {
                if ($a['avg_parse'] === null && $b['avg_parse'] === null) {
                    return strcasecmp($a['member']->name, $b['member']->name);
                }
                if ($a['avg_parse'] === null) return 1;
                if ($b['avg_parse'] === null) return -1;
                return $b['avg_parse'] <=> $a['avg_parse'];
            });
        }

        // Drop empty buckets so the view doesn't render empty headers.
        return array_filter($buckets, fn ($rows) => count($rows) > 0);
    }

    /**
     * Fallback when SpecRoleMap can't classify the member: WCL's parse
     * row knows tank/healer/dps. Tanks come back as null because the
     * importer doesn't record tank parses (tanks aren't ranked on
     * dpsRankings/hpsRankings). DPS without a spec map lands under
     * 'melee' as the safer default.
     */
    private function fallbackRole(?string $wclRole): ?string
    {
        return match ($wclRole) {
            WclActorParse::ROLE_HEALER => SpecRoleMap::ROLE_HEALER,
            WclActorParse::ROLE_DPS    => SpecRoleMap::ROLE_MELEE,
            WclActorParse::ROLE_TANK   => SpecRoleMap::ROLE_TANK,
            default                    => null,
        };
    }
}
