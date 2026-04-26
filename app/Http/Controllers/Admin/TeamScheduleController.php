<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TeamSchedule;
use App\Services\Teams\TeamScheduleResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * /admin/teams/schedule — officer-edited per-team raid schedule.
 *
 * One section per slug declared in config('raidhelper.teams'). The
 * config defaults render in the form fields when no override row
 * exists yet; submitting writes (or updates) a team_schedules row.
 *
 * "Reset to default" deletes the override row, falling back to config.
 */
class TeamScheduleController extends Controller
{
    public function index(): View
    {
        abort_unless(auth()->user()?->can('settings.manage'), 403);

        return view('admin.teams.schedule', [
            'teams' => TeamScheduleResolver::all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->can('settings.manage'), 403);

        $configured = array_keys((array) config('raidhelper.teams', []));

        $validated = $request->validate([
            'teams' => ['required', 'array'],
            'teams.*.raid_days' => ['nullable', 'array'],
            'teams.*.raid_days.*' => ['integer', 'min:1', 'max:7'],
            'teams.*.raid_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
        ], [
            'teams.*.raid_time.regex' => 'Raid time must be HH:MM in 24-hour format.',
        ]);

        foreach ($validated['teams'] as $slug => $row) {
            // Silently drop slugs the form invented that aren't in
            // config — keeps the table free of orphan rows.
            if (! in_array($slug, $configured, true)) {
                continue;
            }

            $days = collect($row['raid_days'] ?? [])
                ->map(fn ($d) => (int) $d)
                ->filter(fn ($d) => $d >= 1 && $d <= 7)
                ->unique()
                ->sort()
                ->values()
                ->all();

            // Empty days list is valid for a team with no scheduled
            // raid nights yet (e.g. an off-season team).
            TeamSchedule::query()->updateOrCreate(
                ['team_slug' => $slug],
                [
                    'raid_days' => $days,
                    'raid_time' => $row['raid_time'],
                    'updated_by_user_id' => auth()->id(),
                ],
            );
        }

        return redirect()
            ->route('admin.teams.schedule.index')
            ->with('status', 'Team schedule updated.');
    }

    /**
     * Drop the override row for a single team so the page falls back
     * to the static config defaults again. Confirmation is enforced by
     * the form button rather than a JS prompt.
     */
    public function reset(Request $request, string $slug): RedirectResponse
    {
        abort_unless(auth()->user()?->can('settings.manage'), 403);

        TeamSchedule::query()->where('team_slug', $slug)->delete();

        return redirect()
            ->route('admin.teams.schedule.index')
            ->with('status', "Reset {$slug} to config defaults.");
    }
}
