<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberAction;
use App\Support\AddAltMacroBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Two-step API powering the "Add alt" modal on the roster page.
 * Generates one `/run GRM.AddAlt("Source","Target")` macro line that
 * links two characters as alts of each other.
 *
 *   POST /roster/add-alt
 *     body: { source_member_id: int, target_name: str }
 *     -> { source: {id,name,class}, target: {id,name,class}, macro: str }
 *
 *   POST /roster/add-alt/confirm
 *     body: { source_member_id: int, target_member_id: int, notes?: str }
 *     -> { logged: int }
 */
class RosterAddAltMacroController extends Controller
{
    public function preview(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->can('roster.kick'), 403);

        $validated = $request->validate([
            'source_member_id' => ['required', 'integer'],
            'target_name' => ['required', 'string', 'max:64'],
        ]);

        $guildKey = (string) config('grm.guild_key');

        $source = Member::query()
            ->forGuild($guildKey)
            ->where('id', $validated['source_member_id'])
            ->where('status', Member::STATUS_ACTIVE)
            ->first();
        if (! $source) {
            return response()->json(['error' => 'Source character not found or not active'], 422);
        }

        // Target is matched by name. The datalist on the page only
        // contains active member names, but a hand-typed name might
        // not match exactly: surface that as a 422 so the officer can
        // pick from the suggestions.
        $target = Member::query()
            ->forGuild($guildKey)
            ->where('name', trim($validated['target_name']))
            ->where('status', Member::STATUS_ACTIVE)
            ->first();
        if (! $target) {
            return response()->json([
                'error' => sprintf('No active member named "%s" in this guild', $validated['target_name']),
            ], 422);
        }

        $built = AddAltMacroBuilder::build($source->name, $target->name);
        if ($built['error'] !== null) {
            return response()->json(['error' => $built['error']], 422);
        }

        return response()->json([
            'source' => ['id' => $source->id, 'name' => $source->name, 'class' => $source->class],
            'target' => ['id' => $target->id, 'name' => $target->name, 'class' => $target->class],
            'macro' => $built['macro'],
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->can('roster.kick'), 403);

        $validated = $request->validate([
            'source_member_id' => ['required', 'integer'],
            'target_member_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $guildKey = (string) config('grm.guild_key');

        $source = Member::query()
            ->forGuild($guildKey)
            ->where('id', $validated['source_member_id'])
            ->where('status', Member::STATUS_ACTIVE)
            ->first();
        $target = Member::query()
            ->forGuild($guildKey)
            ->where('id', $validated['target_member_id'])
            ->where('status', Member::STATUS_ACTIVE)
            ->first();
        if (! $source || ! $target) {
            return response()->json(['error' => 'One or both characters not found or not active'], 422);
        }

        // Log against both members so either's history shows the link.
        // Both rows reference the other's name in the notes column for
        // reconstructibility from the audit log alone.
        $auditPair = sprintf('linked with %s', $target->name);
        MemberAction::query()->create([
            'member_id' => $source->id,
            'reviewed_by_user_id' => auth()->id(),
            'action_type' => MemberAction::TYPE_ADD_ALT_MACRO,
            'decision' => MemberAction::DECISION_ACCEPTED,
            'notes' => $validated['notes'] ? $auditPair . ' / ' . $validated['notes'] : $auditPair,
        ]);
        MemberAction::query()->create([
            'member_id' => $target->id,
            'reviewed_by_user_id' => auth()->id(),
            'action_type' => MemberAction::TYPE_ADD_ALT_MACRO,
            'decision' => MemberAction::DECISION_ACCEPTED,
            'notes' => sprintf('linked with %s', $source->name) . ($validated['notes'] ? ' / ' . $validated['notes'] : ''),
        ]);

        return response()->json(['logged' => 2]);
    }
}
