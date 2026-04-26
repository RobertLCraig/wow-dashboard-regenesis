<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\TeamMapping;
use App\Services\Teams\TeamResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Officer-edited mapping table that turns in-game ranks and Discord
 * role IDs into team values ('mythic', 'heroic', etc.). Used by GRM
 * ingest to populate members.team and by RoleVerifier to populate
 * users.team on login.
 *
 * The page surfaces every rank actually present in the local roster so
 * officers don't have to guess what to map - if a new rank shows up in
 * GRM data, it appears here on the next page load.
 */
class TeamMappingController extends Controller
{
    public function index(): View
    {
        $guildKey = (string) config('grm.guild_key');

        // Every rank name currently observed in the active roster, plus
        // any rank already in the mapping table (so an officer can
        // un-map a rank that's no longer in the roster).
        $rosterRanks = Member::query()
            ->forGuild($guildKey)
            ->active()
            ->whereNotNull('rank_name')
            ->select('rank_name', DB::raw('count(*) as c'))
            ->groupBy('rank_name')
            ->orderByDesc('c')
            ->get();

        $rankMappings = TeamMapping::query()
            ->where('source', TeamMapping::SOURCE_GRM_RANK)
            ->get()
            ->keyBy('key');

        $rankRows = $rosterRanks->map(fn ($r) => [
            'key' => $r->rank_name,
            'count' => (int) $r->c,
            'mapping' => $rankMappings->get($r->rank_name),
        ]);

        // Ranks mapped but not currently in the roster - kept so officers
        // can intentionally remove obsolete mappings.
        $orphanRanks = $rankMappings
            ->reject(fn ($m) => $rosterRanks->contains('rank_name', $m->key))
            ->values()
            ->map(fn ($m) => [
                'key' => $m->key,
                'count' => 0,
                'mapping' => $m,
            ]);

        $rankRows = $rankRows->concat($orphanRanks);

        $roleMappings = TeamMapping::query()
            ->where('source', TeamMapping::SOURCE_DISCORD_ROLE)
            ->orderByDesc('priority')
            ->get();

        return view('admin.teams.index', [
            'rankRows' => $rankRows,
            'roleMappings' => $roleMappings,
            'teams' => TeamMapping::TEAMS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->isOfficerTier(), 403);

        $validated = $request->validate([
            'ranks' => ['array'],
            'ranks.*.key' => ['required', 'string', 'max:255'],
            'ranks.*.team' => ['nullable', 'in:' . implode(',', TeamMapping::TEAMS)],

            'roles' => ['array'],
            'roles.*.key' => ['required', 'string', 'regex:/^[0-9]{15,25}$/'],
            'roles.*.label' => ['nullable', 'string', 'max:255'],
            'roles.*.team' => ['nullable', 'in:' . implode(',', TeamMapping::TEAMS)],
            'roles.*.priority' => ['nullable', 'integer', 'min:0', 'max:1000'],

            'new_role.key' => ['nullable', 'string', 'regex:/^[0-9]{15,25}$/'],
            'new_role.label' => ['nullable', 'string', 'max:255'],
            'new_role.team' => ['nullable', 'in:' . implode(',', TeamMapping::TEAMS)],
            'new_role.priority' => ['nullable', 'integer', 'min:0', 'max:1000'],

            'delete_role_ids' => ['array'],
            'delete_role_ids.*' => ['integer'],
        ]);

        $userId = auth()->id();

        DB::transaction(function () use ($validated, $userId) {
            foreach ($validated['ranks'] ?? [] as $row) {
                TeamMapping::query()->updateOrCreate(
                    ['source' => TeamMapping::SOURCE_GRM_RANK, 'key' => $row['key']],
                    ['team' => $row['team'] ?? null, 'updated_by_user_id' => $userId]
                );
            }

            foreach ($validated['roles'] ?? [] as $row) {
                TeamMapping::query()->updateOrCreate(
                    ['source' => TeamMapping::SOURCE_DISCORD_ROLE, 'key' => $row['key']],
                    [
                        'label' => $row['label'] ?? null,
                        'team' => $row['team'] ?? null,
                        'priority' => (int) ($row['priority'] ?? 100),
                        'updated_by_user_id' => $userId,
                    ]
                );
            }

            $newRole = $validated['new_role'] ?? null;
            if ($newRole && ! empty($newRole['key'])) {
                TeamMapping::query()->updateOrCreate(
                    ['source' => TeamMapping::SOURCE_DISCORD_ROLE, 'key' => $newRole['key']],
                    [
                        'label' => $newRole['label'] ?? null,
                        'team' => $newRole['team'] ?? null,
                        'priority' => (int) ($newRole['priority'] ?? 100),
                        'updated_by_user_id' => $userId,
                    ]
                );
            }

            if (! empty($validated['delete_role_ids'])) {
                TeamMapping::query()
                    ->where('source', TeamMapping::SOURCE_DISCORD_ROLE)
                    ->whereIn('id', $validated['delete_role_ids'])
                    ->delete();
            }
        });

        $resolver = app(TeamResolver::class);
        $resolver->flush();
        $updated = $resolver->recomputeMembers((string) config('grm.guild_key'));

        return redirect()
            ->route('admin.teams.index')
            ->with('status', "Saved. Recomputed team for {$updated} members.");
    }
}
