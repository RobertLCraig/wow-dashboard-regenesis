<?php

namespace App\Services\Blizzard;

use App\Models\Member;
use App\Models\MemberRaidSnapshot;
use Illuminate\Support\Collection;

/**
 * Pure read-side helper that turns a roster + the latest Blizzard raid
 * snapshots into AOTC / CE counts rolled up by alt cohort. A cohort is
 * one player's worth of characters: members sharing alt_group_id, plus
 * any member without a group treated as a one-character cohort. A
 * cohort counts as AOTC-cleared if any of its characters has the
 * achievement, matching how officers think about the gap (a healer
 * who got AOTC on their alt is not a missing-AOTC slot).
 *
 * No DB writes, no config reads. Tests can pass mock data directly.
 */
class AotcCohortGapBuilder
{
    public function __construct(private readonly RaidProgressionAnalyzer $analyzer)
    {
    }

    /**
     * @param  Collection<int, Member>  $activeMembers Active roster, with alt_group_id + main_member_id loaded.
     * @param  Collection<int, MemberRaidSnapshot>  $snapshots Latest Blizzard raid snapshots, one per member.
     * @return ?array{
     *   tier: array{expansion_id:int, expansion_name:string, instance_id:int, instance_name:string},
     *   active_count: int,
     *   active_member_count: int,
     *   has_aotc: list<array{name:string, class:?string, alts:list<string>}>,
     *   missing_aotc: list<array{name:string, class:?string, alts:list<string>}>,
     *   has_ce: list<array{name:string, class:?string, alts:list<string>}>,
     * }
     */
    public function build(Collection $activeMembers, Collection $snapshots): ?array
    {
        if ($activeMembers->isEmpty() || $snapshots->isEmpty()) {
            return null;
        }

        $tier = $this->analyzer->currentTier($snapshots);
        if ($tier === null) {
            return null;
        }

        $byMember = $snapshots->keyBy('member_id');

        // Bucket members by cohort key. Members sharing alt_group_id
        // group together; members without a group key are their own
        // single-char cohort, keyed by id under a "solo:" prefix so
        // they don't collide with real group ids.
        $cohorts = [];
        foreach ($activeMembers as $m) {
            $key = $m->alt_group_id !== null ? "grp:{$m->alt_group_id}" : "solo:{$m->id}";
            $cohorts[$key][] = $m;
        }

        $hasAotc = [];
        $missingAotc = [];
        $hasCe = [];

        foreach ($cohorts as $cohortMembers) {
            $main = $this->resolveCohortMain($cohortMembers);

            $cohortAotc = false;
            $cohortCe = false;
            foreach ($cohortMembers as $m) {
                $snap = $byMember->get($m->id);
                if ($snap === null) {
                    continue;
                }
                if ($this->analyzer->hasAotcOn($snap, $tier['instance_id'])) {
                    $cohortAotc = true;
                    if ($this->analyzer->hasCeOn($snap, $tier['instance_id'])) {
                        $cohortCe = true;
                    }
                }
            }

            $alts = [];
            foreach ($cohortMembers as $m) {
                if ($m->id !== $main->id) {
                    $alts[] = $m->name;
                }
            }

            $entry = [
                'name' => $main->name,
                'class' => $main->class,
                'alts' => $alts,
            ];

            if ($cohortAotc) {
                $hasAotc[] = $entry;
                if ($cohortCe) {
                    $hasCe[] = $entry;
                }
            } else {
                $missingAotc[] = $entry;
            }
        }

        $sortByName = static function (array &$list): void {
            usort($list, static fn ($a, $b) => strcasecmp((string) $a['name'], (string) $b['name']));
        };
        $sortByName($hasAotc);
        $sortByName($missingAotc);
        $sortByName($hasCe);

        return [
            'tier' => $tier,
            'active_count' => count($cohorts),
            'active_member_count' => $activeMembers->count(),
            'has_aotc' => $hasAotc,
            'missing_aotc' => $missingAotc,
            'has_ce' => $hasCe,
        ];
    }

    /**
     * Pick the cohort's display character. Declared main first
     * (members.main_member_id is NULL on the main itself, points to it
     * on alts). Failing that, the first member without a
     * main_member_id, falling back to the first member outright. Both
     * fallbacks rely on the input being ordered by name so the chosen
     * label stays stable across snapshots.
     *
     * @param  list<Member>  $cohortMembers
     */
    private function resolveCohortMain(array $cohortMembers): Member
    {
        $altMainId = null;
        foreach ($cohortMembers as $m) {
            if ($m->main_member_id !== null) {
                $altMainId = (int) $m->main_member_id;
                break;
            }
        }
        if ($altMainId !== null) {
            foreach ($cohortMembers as $m) {
                if ($m->id === $altMainId) {
                    return $m;
                }
            }
        }
        foreach ($cohortMembers as $m) {
            if ($m->main_member_id === null) {
                return $m;
            }
        }

        return $cohortMembers[0];
    }
}
