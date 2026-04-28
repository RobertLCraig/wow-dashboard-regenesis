<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\TeamMapping;
use App\Services\Teams\TeamResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Per-member team override. Lets an officer pick which raid team(s) a
 * specific character belongs to, regardless of what rank says. Used for
 * Officer / Alt rank players who actually play in heroic, raid leaders
 * who only do heroic when their rank maps to mythic, players who run in
 * both teams, and any other case where the rank-to-team mapping under
 * /admin/teams isn't enough.
 *
 * Empty selection routes through clearOverrides() in the resolver so it
 * reverts to whatever rank says. The team mapping admin page is still
 * the right place to change the default-for-everyone behaviour.
 */
class CharacterTeamOverrideController extends Controller
{
    public function update(string $nameRealm, Request $request, TeamResolver $resolver): RedirectResponse
    {
        abort_unless(auth()->user()?->isOfficerTier(), 403);

        $guildKey = (string) config('grm.guild_key');
        $member = Member::query()
            ->forGuild($guildKey)
            ->where('name', $nameRealm)
            ->firstOrFail();

        $validated = $request->validate([
            'teams' => ['array'],
            'teams.*' => ['string', 'in:' . implode(',', TeamMapping::TEAMS)],
            'action' => ['nullable', 'string', 'in:save,clear'],
        ]);

        if (($validated['action'] ?? 'save') === 'clear') {
            $resolver->clearOverrides($member);
            $status = "Cleared team override on {$member->name}; reverted to rank-derived.";
        } else {
            $teams = $validated['teams'] ?? [];
            $resolver->setOverrides($member, $teams, auth()->id());
            $status = $teams === []
                ? "Cleared team override on {$member->name}; reverted to rank-derived."
                : "Saved team override on {$member->name}.";
        }

        return redirect()
            ->route('character.show', $member->name)
            ->with('status', $status);
    }
}
