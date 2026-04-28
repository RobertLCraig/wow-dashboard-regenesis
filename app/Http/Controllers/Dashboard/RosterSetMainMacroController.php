<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberAction;
use App\Support\SetMainMacroBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Two-step API powering the "Set as main" modal on the roster page.
 *
 * The dashboard never mutates GRM data; it only generates a macro the
 * officer pastes into WoW. The next GRM ingest reflects whichever
 * character is now the group's main. Same pattern as the kick-macro
 * flow ({@see RosterKickMacroController}).
 *
 *   POST /roster/set-main
 *     body: { member_ids: [int, ...] }
 *     -> { macros: [str, ...], characters: [{id,name,class},...],
 *          skipped: [{id,name,reason}], oversized: [name,...] }
 *
 *   POST /roster/set-main/confirm
 *     body: { member_ids: [int, ...], notes?: str }
 *     -> { logged: int }
 */
class RosterSetMainMacroController extends Controller
{
    public function preview(Request $request): JsonResponse
    {
        // The set-main operation is gated behind the same officer
        // capability as kick: both are write actions that round-trip
        // through GRM and need an audit trail.
        abort_unless(auth()->user()?->can('roster.kick'), 403);

        $validated = $request->validate([
            'member_ids' => ['required', 'array', 'min:1'],
            'member_ids.*' => ['integer'],
        ]);

        $members = Member::query()
            ->forGuild((string) config('grm.guild_key'))
            ->whereIn('id', $validated['member_ids'])
            ->orderBy('name')
            ->get();

        $characters = [];
        $skipped = [];
        $names = [];
        foreach ($members as $m) {
            // Set Main only makes sense on active members in an alt
            // group. Outside that, we surface them as `skipped` rather
            // than silently dropping so the officer sees the reason.
            if ($m->status !== Member::STATUS_ACTIVE) {
                $skipped[] = [
                    'id' => $m->id,
                    'name' => $m->name,
                    'reason' => $m->status === Member::STATUS_BANNED ? 'banned' : 'no longer in guild',
                ];
                continue;
            }
            if ($m->alt_group_id === null) {
                $skipped[] = [
                    'id' => $m->id,
                    'name' => $m->name,
                    'reason' => 'not linked to an alt group in GRM',
                ];
                continue;
            }
            $characters[] = [
                'id' => $m->id,
                'name' => $m->name,
                'class' => $m->class,
            ];
            $names[] = $m->name;
        }

        $built = SetMainMacroBuilder::build($names);

        return response()->json([
            'macros' => $built['macros'],
            'oversized' => $built['oversized'],
            'characters' => $characters,
            'skipped' => $skipped,
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->can('roster.kick'), 403);

        $validated = $request->validate([
            'member_ids' => ['required', 'array', 'min:1'],
            'member_ids.*' => ['integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $members = Member::query()
            ->forGuild((string) config('grm.guild_key'))
            ->whereIn('id', $validated['member_ids'])
            ->where('status', Member::STATUS_ACTIVE)
            ->whereNotNull('alt_group_id')
            ->get();

        $logged = 0;
        foreach ($members as $m) {
            MemberAction::query()->create([
                'member_id' => $m->id,
                'reviewed_by_user_id' => auth()->id(),
                'action_type' => MemberAction::TYPE_SET_MAIN_MACRO,
                'decision' => MemberAction::DECISION_ACCEPTED,
                'notes' => $validated['notes'] ?? null,
            ]);
            $logged++;
        }

        return response()->json(['logged' => $logged]);
    }
}
