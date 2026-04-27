<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\RaidEvent;
use App\Models\TeamMapping;
use App\Models\WclFight;
use App\Services\Composition\TeamCompositionBuilder;
use App\Services\Teams\TeamScheduleResolver;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Composition planner per raid team. Aggregates the WCL parse data
 * we already pull (commit 978fc62 onwards) into a role-grouped view
 * so a raid lead can see at a glance who's their strongest tank,
 * healer, melee and ranged DPS over a recent window.
 *
 * Optional event-scoping (?event=<raid_event_id>) restricts the
 * composition to team members who actually signed up for a specific
 * Raid-Helper event, so "who's coming tonight, and how do they
 * look?" lands in one query string instead of cross-referencing
 * the event page by hand.
 */
class CompositionController extends Controller
{
    /** Statuses that count as "coming tonight" for signup filtering. */
    private const ATTENDING_STATUSES_EXCLUDE = ['absence', 'absent', 'bench', 'declined', 'dps_bench', 'declined_late'];

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

        // Upcoming events for this team's channel, used both for the
        // dropdown and to look up the picked event. Limited to the
        // next 14 days so the dropdown doesn't grow unbounded.
        $teamChannelId = TeamScheduleResolver::for($team)['channel_id'] ?? null;
        $upcomingEvents = $teamChannelId
            ? RaidEvent::query()
                ->where('channel_id', $teamChannelId)
                ->where('starts_at', '>=', now()->subHours(6))
                ->where('starts_at', '<=', now()->addDays(14))
                ->orderBy('starts_at')
                ->get()
            : collect();

        $eventId = $request->query('event');
        $event = $eventId ? $upcomingEvents->firstWhere('id', (int) $eventId) : null;

        $members = Member::query()
            ->forGuild($guildKey)
            ->active()
            ->whereIn('team', $config['keys'])
            ->orderBy('name')
            ->get();

        $attendingNames = collect();
        $signedUpCount = null;
        if ($event) {
            $attendingNames = $event->signups()
                ->whereNotIn('status', self::ATTENDING_STATUSES_EXCLUDE)
                ->where('is_fake', false)
                ->pluck('name')
                ->map(fn ($n) => $this->matchKey($n));

            $signedUpCount = $attendingNames->count();

            $members = $members->filter(fn ($m) => $attendingNames->contains($this->matchKey($m->name)))->values();
        }

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
            'upcomingEvents'  => $upcomingEvents,
            'event'           => $event,
            'signedUpCount'   => $signedUpCount,
        ]);
    }

    /**
     * Normalise a name for cross-source matching. signup.name comes
     * in as either "Bob" or "Bob-Silvermoon" depending on RaidHelper
     * template; members.name is always "Bob-Silvermoon" in our store.
     * Match on the first segment, lowercased.
     */
    private function matchKey(string $name): string
    {
        return strtolower(explode('-', trim($name), 2)[0]);
    }
}
