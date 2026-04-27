<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\TeamMapping;
use App\Models\WclFight;
use App\Services\Composition\TeamCompositionBuilder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Composition planner per raid team. Aggregates the WCL parse data
 * we already pull (commit 978fc62 onwards) into a role-grouped view
 * so a raid lead can see at a glance who's their strongest tank,
 * healer, melee and ranged DPS over a recent window.
 *
 * Same gate as the team dashboards (dashboard.team.{slug}.view); the
 * route lives at /composition/{team} so it's discoverable from the
 * sidebar without nesting under the team page URL.
 */
class CompositionController extends Controller
{
    /** @var array<string, array{label:string, keys:list<string>, defaultDifficulty:int}> */
    private const TEAMS = [
        'heroic' => [
            'label' => 'Heroic',
            'keys'  => [TeamMapping::TEAM_HEROIC, TeamMapping::TEAM_HEROIC_TRIAL],
            'defaultDifficulty' => WclFight::DIFFICULTY_HEROIC,
        ],
        'mythic' => [
            'label' => 'Mythic',
            'keys'  => [TeamMapping::TEAM_MYTHIC, TeamMapping::TEAM_MYTHIC_TRIAL],
            'defaultDifficulty' => WclFight::DIFFICULTY_MYTHIC,
        ],
    ];

    public function show(string $team, Request $request): View
    {
        abort_unless(isset(self::TEAMS[$team]), 404);
        abort_unless(auth()->user()?->can("dashboard.team.{$team}.view"), 403);

        $config = self::TEAMS[$team];
        $guildKey = (string) config('grm.guild_key');

        $days = (int) $request->query('days', 14);
        if (! in_array($days, [7, 14, 30, 60], true)) {
            $days = 14;
        }

        $difficulty = $request->query('difficulty');
        if ($difficulty === 'all') {
            $difficulties = null;
        } else {
            $diffInt = (int) ($difficulty ?? $config['defaultDifficulty']);
            $difficulties = in_array($diffInt, [
                WclFight::DIFFICULTY_HEROIC,
                WclFight::DIFFICULTY_MYTHIC,
            ], true) ? [$diffInt] : [$config['defaultDifficulty']];
        }

        $members = Member::query()
            ->forGuild($guildKey)
            ->active()
            ->whereIn('team', $config['keys'])
            ->orderBy('name')
            ->get();

        $buckets = (new TeamCompositionBuilder)->build(
            members: $members,
            days: $days,
            difficulties: $difficulties,
        );

        return view('dashboard.composition', [
            'teamSlug'        => $team,
            'teamLabel'       => $config['label'],
            'days'            => $days,
            'difficulty'      => $difficulty === 'all' ? 'all' : ($difficulties[0] ?? $config['defaultDifficulty']),
            'buckets'         => $buckets,
            'memberCount'     => $members->count(),
        ]);
    }
}
