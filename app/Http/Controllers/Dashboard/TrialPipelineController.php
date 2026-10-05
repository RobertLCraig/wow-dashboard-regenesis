<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\DiscordRecruitForm;
use App\Models\Member;
use App\Models\MemberEvent;
use App\Models\TeamMapping;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Where each recruit stands: Applied (a recent new-recruits form whose
 * character is on no team), Trial, Raider (member_teams on active
 * members) and Alumni (left or banned, with team history). Read-only;
 * stages move the way they always have, by rank or team override.
 */
class TrialPipelineController extends Controller
{
    /** How far back a recruit form still counts as an open application. */
    public const APPLIED_WINDOW_DAYS = 60;

    private const TRIAL_TEAMS = [TeamMapping::TEAM_MYTHIC_TRIAL, TeamMapping::TEAM_HEROIC_TRIAL];

    private const RAID_TEAMS = [TeamMapping::TEAM_MYTHIC, TeamMapping::TEAM_HEROIC];

    public function __invoke(): View
    {
        $guildKey = (string) config('grm.guild_key');

        $teamed = Member::query()->forGuild($guildKey)->active()->hasAnyTeam()
            ->with('teams')->orderBy('name')->get();
        $alumni = Member::withTrashed()->forGuild($guildKey)
            ->whereIn('status', [Member::STATUS_LEFT, Member::STATUS_BANNED])
            ->whereHas('events', fn ($q) => $q->where('type', MemberEvent::TYPE_TEAM_JOINED))
            ->orderBy('name')->get();

        $history = MemberEvent::query()
            ->whereIn('member_id', $teamed->pluck('id')->merge($alumni->pluck('id')))
            ->ofType([MemberEvent::TYPE_TEAM_JOINED, MemberEvent::TYPE_TEAM_LEFT])
            ->orderBy('occurred_at')->orderBy('id')
            ->get()->groupBy('member_id');

        $trial = collect();
        $raider = collect();
        foreach ($teamed as $member) {
            $teams = $member->teams->pluck('team');
            $events = $history->get($member->id, collect());
            $trialTeam = collect(self::TRIAL_TEAMS)->first(fn ($t) => $teams->contains($t));

            if ($trialTeam !== null) {
                $joined = $events->last(fn ($e) => $e->type === MemberEvent::TYPE_TEAM_JOINED
                    && ($e->payload_json['team'] ?? null) === $trialTeam);
                $trial->push([
                    'member' => $member,
                    'team' => $trialTeam,
                    'days' => $joined ? (int) floor($joined->occurred_at->diffInDays(now())) : null,
                    'history' => $events,
                ]);
            } elseif ($teams->intersect(self::RAID_TEAMS)->isNotEmpty()) {
                $raider->push(['member' => $member, 'team' => $teams->intersect(self::RAID_TEAMS)->first(), 'history' => $events]);
            }
        }

        $leftAt = MemberEvent::query()
            ->whereIn('member_id', $alumni->pluck('id'))
            ->ofType([MemberEvent::TYPE_LEFT, MemberEvent::TYPE_KICKED, MemberEvent::TYPE_BANNED])
            ->get()->groupBy('member_id')
            ->map(fn (Collection $e) => $e->max('occurred_at'));

        $alumniRows = $alumni->map(function (Member $member) use ($history, $leftAt) {
            $events = $history->get($member->id, collect());

            return [
                'member' => $member,
                'team' => $events->last(fn ($e) => $e->type === MemberEvent::TYPE_TEAM_JOINED)?->payload_json['team'] ?? null,
                'left_at' => $leftAt->get($member->id),
                'history' => $events,
            ];
        });

        return view('dashboard.pipeline', [
            'applied' => $this->applied($guildKey),
            'trial' => $trial,
            'raider' => $raider,
            'alumni' => $alumniRows,
        ]);
    }

    /**
     * Recent recruit forms whose character is on no team. Nothing links a
     * form to a member, so the form's character_name is matched against
     * members.name before the "-Realm" part, case-insensitively.
     */
    private function applied(string $guildKey): Collection
    {
        $base = fn (string $name) => mb_strtolower(trim(explode('-', $name)[0]));

        // active() like Trial and Raider: a member who left keeps their team row.
        $onATeam = Member::query()->forGuild($guildKey)->active()->hasAnyTeam()
            ->pluck('name')->map($base)->flip();

        return DiscordRecruitForm::query()
            ->where('posted_at', '>=', now()->subDays(self::APPLIED_WINDOW_DAYS))
            ->orderByDesc('posted_at')
            ->get()
            ->reject(fn (DiscordRecruitForm $f) => $onATeam->has($base($f->character_name)))
            ->unique(fn (DiscordRecruitForm $f) => $base($f->character_name))
            ->values();
    }
}
