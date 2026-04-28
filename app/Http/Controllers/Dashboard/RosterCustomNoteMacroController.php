<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberAction;
use App\Support\CustomNoteMacroBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Two-step API powering the "Edit GRM custom note" modal on the roster
 * page. Generates one `/run GRM_API.EditCustomNote(...)` macro line.
 *
 * Note: targets GRM's own custom-note field, NOT the Blizzard Public
 * or Officer note slots. The dashboard never touches those.
 *
 *   POST /roster/custom-note
 *     body: { member_id: int, note: str, replace: bool }
 *     -> { character: {id,name,class,current_note}, macro: str }
 *
 *   POST /roster/custom-note/confirm
 *     body: { member_id: int, note: str, replace: bool, notes?: str }
 *     -> { logged: int }
 */
class RosterCustomNoteMacroController extends Controller
{
    public function preview(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->can('roster.kick'), 403);

        $validated = $request->validate([
            'member_id' => ['required', 'integer'],
            'note' => ['required', 'string', 'max:' . CustomNoteMacroBuilder::MAX_NOTE_LENGTH],
            'replace' => ['required', 'boolean'],
        ]);

        $member = Member::query()
            ->forGuild((string) config('grm.guild_key'))
            ->where('id', $validated['member_id'])
            ->where('status', Member::STATUS_ACTIVE)
            ->first();

        if (! $member) {
            return response()->json(['error' => 'Member not found or no longer active'], 422);
        }

        $built = CustomNoteMacroBuilder::build(
            $member->name,
            $validated['note'],
            (bool) $validated['replace'],
        );

        if ($built['error'] !== null) {
            return response()->json(['error' => $built['error']], 422);
        }

        return response()->json([
            'character' => [
                'id' => $member->id,
                'name' => $member->name,
                'class' => $member->class,
                'current_note' => $member->custom_note,
            ],
            'macro' => $built['macro'],
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->can('roster.kick'), 403);

        $validated = $request->validate([
            'member_id' => ['required', 'integer'],
            'note' => ['required', 'string', 'max:' . CustomNoteMacroBuilder::MAX_NOTE_LENGTH],
            'replace' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $member = Member::query()
            ->forGuild((string) config('grm.guild_key'))
            ->where('id', $validated['member_id'])
            ->where('status', Member::STATUS_ACTIVE)
            ->first();

        if (! $member) {
            return response()->json(['error' => 'Member not found or no longer active'], 422);
        }

        // Stash the new note + replace flag into the MemberAction's
        // free-form notes column (prefixed) so the audit trail captures
        // exactly what the officer asked GRM to write.
        $auditPrefix = sprintf(
            '[%s | %s]',
            $validated['replace'] ? 'replace' : 'append',
            mb_strimwidth($validated['note'], 0, 80, '...'),
        );
        $auditNotes = $validated['notes']
            ? $auditPrefix . ' ' . $validated['notes']
            : $auditPrefix;

        MemberAction::query()->create([
            'member_id' => $member->id,
            'reviewed_by_user_id' => auth()->id(),
            'action_type' => MemberAction::TYPE_CUSTOM_NOTE_MACRO,
            'decision' => MemberAction::DECISION_ACCEPTED,
            'notes' => $auditNotes,
        ]);

        return response()->json(['logged' => 1]);
    }
}
