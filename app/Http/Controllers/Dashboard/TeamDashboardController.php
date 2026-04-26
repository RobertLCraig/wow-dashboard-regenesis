<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberSnapshot;
use App\Models\RaidEvent;
use App\Models\Snapshot;
use App\Models\TeamMapping;
use Illuminate\View\View;

/**
 * Per-team dashboard. Same widget shape as the General page but scoped
 * to a single raid team's members and a single raid signup channel,
 * plus a quick-create panel preset for that team's raid nights.
 *
 * Trial teams roll up under the parent: /dashboard/heroic covers
 * heroic + heroic_trial, /dashboard/mythic covers mythic + mythic_trial.
 */
class TeamDashboardController extends Controller
{
    public function heroic(): View
    {
        return $this->show('heroic', [TeamMapping::TEAM_HEROIC, TeamMapping::TEAM_HEROIC_TRIAL]);
    }

    public function mythic(): View
    {
        return $this->show('mythic', [TeamMapping::TEAM_MYTHIC, TeamMapping::TEAM_MYTHIC_TRIAL]);
    }

    /**
     * @param  list<string>  $teamKeys  members.team values rolled up into this page
     */
    private function show(string $slug, array $teamKeys): View
    {
        abort_unless(auth()->user()?->can("dashboard.team.{$slug}.view"), 403);

        $guildKey = (string) config('grm.guild_key');
        $preset = (array) config("raidhelper.teams.{$slug}");

        $members = Member::query()
            ->forGuild($guildKey)
            ->active()
            ->whereIn('team', $teamKeys)
            ->orderBy('name')
            ->get();

        return view("dashboard.team.{$slug}", [
            'lastSnapshot' => Snapshot::query()
                ->where('guild_key', $guildKey)
                ->latest('captured_at')
                ->first(),
            'teamSlug' => $slug,
            'teamKeys' => $teamKeys,
            'preset' => $preset,
            'roster' => $this->teamRoster($guildKey, $members),
            'raidSummary' => $this->teamRaidSummary($guildKey, $members),
            'upcomingEvents' => $this->teamUpcomingEvents($preset['channel_id'] ?? null),
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Member>  $members
     * @return array{captured_at: ?\Carbon\CarbonInterface, rows: \Illuminate\Support\Collection}
     */
    private function teamRoster(string $guildKey, $members): array
    {
        $latest = $this->latestRaiderioSnapshot($guildKey);
        $snapsByMember = $latest
            ? MemberSnapshot::query()->where('snapshot_id', $latest->id)->get()->keyBy('member_id')
            : collect();

        $rows = $members->map(function (Member $m) use ($snapsByMember) {
            $snap = $snapsByMember->get($m->id);
            return [
                'member' => $m,
                'snap' => $snap,
                // Best raid summary across all instances on this snap;
                // pull from the same field the team-progression widget reads.
                'raid_summary' => $this->bestRaidSummary($snap),
            ];
        });

        // Sort by ilvl desc, then by name. Members without a RIO snapshot
        // fall to the bottom rather than mid-list.
        $rows = $rows->sortBy([
            fn ($a, $b) => ($b['snap']?->ilvl ?? 0) <=> ($a['snap']?->ilvl ?? 0),
            fn ($a, $b) => strcasecmp($a['member']->name, $b['member']->name),
        ])->values();

        return ['captured_at' => $latest?->captured_at, 'rows' => $rows];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Member>  $members
     * @return array{count:int, with_data:int, best_summary:?string, best_key:?string, avg_ilvl:?int, top_rio:?float, top_key:?int}
     */
    private function teamRaidSummary(string $guildKey, $members): array
    {
        $latest = $this->latestRaiderioSnapshot($guildKey);
        if (! $latest || $members->isEmpty()) {
            return [
                'count' => $members->count(), 'with_data' => 0,
                'best_summary' => null, 'best_key' => null,
                'avg_ilvl' => null, 'top_rio' => null, 'top_key' => null,
            ];
        }

        $snaps = MemberSnapshot::query()
            ->where('snapshot_id', $latest->id)
            ->whereIn('member_id', $members->pluck('id'))
            ->get();

        $bestSummary = null;
        $bestKey = null;
        $bestM = -1;
        $bestH = -1;
        foreach ($snaps as $snap) {
            foreach ((array) ($snap->raid_progression_json ?? []) as $instanceKey => $p) {
                if (! is_array($p)) continue;
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

        return [
            'count' => $members->count(),
            'with_data' => $snaps->count(),
            'best_summary' => $bestSummary,
            'best_key' => $bestKey,
            'avg_ilvl' => $ilvls ? (int) round(array_sum($ilvls) / count($ilvls)) : null,
            'top_rio' => $rios ? (float) max($rios) : null,
            'top_key' => $keys ? (int) max($keys) : null,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, RaidEvent>
     */
    private function teamUpcomingEvents(?string $channelId): \Illuminate\Support\Collection
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

    private function latestRaiderioSnapshot(string $guildKey): ?Snapshot
    {
        return Snapshot::query()
            ->where('guild_key', $guildKey)
            ->where('source', Snapshot::SOURCE_RAIDERIO)
            ->latest('captured_at')
            ->first();
    }

    /**
     * Pick the most-progressed instance summary off a member snapshot:
     * most mythic kills wins, heroic kills as tiebreak.
     */
    private function bestRaidSummary(?MemberSnapshot $snap): ?string
    {
        if (! $snap) return null;
        $best = null;
        $bestM = -1;
        $bestH = -1;
        foreach ((array) ($snap->raid_progression_json ?? []) as $p) {
            if (! is_array($p)) continue;
            $m = (int) ($p['mythic_bosses_killed'] ?? 0);
            $h = (int) ($p['heroic_bosses_killed'] ?? 0);
            if ($m > $bestM || ($m === $bestM && $h > $bestH)) {
                $bestM = $m;
                $bestH = $h;
                $best = is_string($p['summary'] ?? null) ? $p['summary'] : null;
            }
        }
        return $best;
    }
}
