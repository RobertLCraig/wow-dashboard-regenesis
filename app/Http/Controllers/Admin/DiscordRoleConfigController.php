<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DiscordRole;
use App\Models\TeamRoleMention;
use App\Services\Discord\DiscordRoleMentionResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * /admin/discord-roles — officer-edited Discord role pingability.
 *
 * One form, two halves:
 *
 *   1. Discord roles (CRUD-lite). Each row is a {name, discord_id}
 *      pair. Officers can edit existing rows, add new ones via blank
 *      template inputs, or delete rows by ticking the delete checkbox.
 *
 *   2. Per-team allocations. A grid of (team x role) checkboxes that
 *      writes/deletes team_role_mentions rows. Order in the team's
 *      "Will ping" preview matches the order the officer picked them
 *      (preserved through the `position` column on the pivot).
 *
 * The whole form submits as one POST so officers can re-allocate roles
 * + adjust snowflakes in one trip. EventController reads via
 * DiscordRoleMentionResolver.
 */
class DiscordRoleConfigController extends Controller
{
    public function index(): View
    {
        abort_unless(auth()->user()?->can('settings.manage'), 403);

        return view('admin.discord-roles.index', [
            'roles' => DiscordRole::query()->orderBy('sort_order')->orderBy('name')->get(),
            'teams' => (array) config('raidhelper.teams', []),
            'assignments' => DiscordRoleMentionResolver::assignmentsByTeam(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->can('settings.manage'), 403);

        $configuredTeams = array_keys((array) config('raidhelper.teams', []));

        $validated = $request->validate([
            // Existing rows. Keys are role IDs as strings; empty `name`
            // is the signal for "blank slot ignore me", `delete` removes.
            'roles' => ['nullable', 'array'],
            'roles.*.name' => ['nullable', 'string', 'max:64'],
            'roles.*.discord_id' => ['nullable', 'string', 'regex:/^\d{15,25}$/'],
            'roles.*.delete' => ['nullable', 'boolean'],
            // Brand-new role rows. Same shape, indexed numerically.
            'new_roles' => ['nullable', 'array'],
            'new_roles.*.name' => ['nullable', 'string', 'max:64'],
            'new_roles.*.discord_id' => ['nullable', 'string', 'regex:/^\d{15,25}$/'],
            // Per-team role-ID lists. Each value is an array of strings
            // we coerce to ints below; unknown ids are dropped silently.
            'teams' => ['nullable', 'array'],
            'teams.*.role_ids' => ['nullable', 'array'],
            'teams.*.role_ids.*' => ['integer'],
        ], [
            'roles.*.discord_id.regex' => 'Discord role IDs must be 15-25 digits (right-click role with Developer Mode on > Copy ID).',
            'new_roles.*.discord_id.regex' => 'Discord role IDs must be 15-25 digits (right-click role with Developer Mode on > Copy ID).',
        ]);

        $userId = auth()->id();

        DB::transaction(function () use ($validated, $configuredTeams, $userId) {
            // 1. Update / delete existing rows.
            foreach ($validated['roles'] ?? [] as $id => $row) {
                $role = DiscordRole::query()->find($id);
                if (! $role) {
                    continue;
                }
                if (! empty($row['delete'])) {
                    // Cascades the team_role_mentions rows.
                    $role->delete();
                    continue;
                }
                if (empty($row['name'])) {
                    continue;
                }
                $role->fill([
                    'name' => $row['name'],
                    'discord_id' => $row['discord_id'] ?? null,
                    'updated_by_user_id' => $userId,
                ])->save();
            }

            // 2. Insert new rows. Skip blanks. Sort_order picks up where
            //    the existing list left off so the admin page still
            //    renders them grouped.
            $nextSort = (int) (DiscordRole::query()->max('sort_order') ?? 0) + 10;
            foreach ($validated['new_roles'] ?? [] as $row) {
                if (empty($row['name'])) {
                    continue;
                }
                DiscordRole::query()->create([
                    'name' => $row['name'],
                    'discord_id' => $row['discord_id'] ?? null,
                    'sort_order' => $nextSort,
                    'updated_by_user_id' => $userId,
                ]);
                $nextSort += 10;
            }

            // 3. Re-sync team allocations. We delete-then-insert per team
            //    so officers can fully reorganise without leftovers; the
            //    `position` column on the pivot preserves checkbox order
            //    so the "Will ping" preview reads naturally.
            $validRoleIds = DiscordRole::query()->pluck('id')->all();
            foreach ($validated['teams'] ?? [] as $slug => $row) {
                if (! in_array($slug, $configuredTeams, true)) {
                    continue;
                }
                $picked = collect($row['role_ids'] ?? [])
                    ->map(fn ($v) => (int) $v)
                    ->filter(fn ($id) => in_array($id, $validRoleIds, true))
                    ->unique()
                    ->values();

                TeamRoleMention::query()->where('team_slug', $slug)->delete();
                foreach ($picked as $position => $roleId) {
                    TeamRoleMention::query()->create([
                        'team_slug' => $slug,
                        'discord_role_id' => $roleId,
                        'position' => $position,
                    ]);
                }
            }
        });

        return redirect()
            ->route('admin.discord-roles.index')
            ->with('status', 'Discord role mentions updated.');
    }
}
