<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Officer review of GRM's recommend_* flags. Records the decision
 * locally; we deliberately don't push back to GRM (the addon has no
 * inbound API and writing the SavedVariables file from outside the game
 * would race with the running client).
 */
class MemberActionController extends Controller
{
    public function store(Request $request, Member $member): RedirectResponse
    {
        abort_unless(auth()->user()?->can('members.review'), 403);

        $validated = $request->validate([
            'action_type' => ['required', 'in:promote,demote,kick,special'],
            'decision' => ['required', 'in:accepted,dismissed,snoozed'],
            'snooze_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $snoozeUntil = null;
        if ($validated['decision'] === MemberAction::DECISION_SNOOZED) {
            $snoozeUntil = now()->addDays($validated['snooze_days'] ?? 7);
        }

        MemberAction::query()->create([
            'member_id' => $member->id,
            'reviewed_by_user_id' => auth()->id(),
            'action_type' => $validated['action_type'],
            'decision' => $validated['decision'],
            'snooze_until' => $snoozeUntil,
            'notes' => $validated['notes'] ?? null,
        ]);

        return back()->with('status', "Recorded $validated[decision] for $member->name");
    }
}
