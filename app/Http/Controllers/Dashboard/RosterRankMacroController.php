<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberAction;
use App\Support\RankMacroBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Two-step API powering the "Promote" / "Demote" macro modal on the
 * roster page. Generates `/gpromote` or `/gdemote` lines for one or
 * more characters; same kick-macro pattern: dashboard never mutates
 * the in-game state, officer pastes the macro into WoW, the next
 * GRM ingest catches up.
 *
 *   POST /roster/rank-macro
 *     body: { op: "promote" | "demote", member_ids: [int, ...] }
 *     -> { op, macros: [str, ...], characters: [{id,name,char_name,class},...],
 *          skipped: [{id,name,reason}], oversized: [name,...] }
 *
 *   POST /roster/rank-macro/confirm
 *     body: { op: "promote" | "demote", member_ids: [int, ...], notes?: str }
 *     -> { logged: int }
 */
class RosterRankMacroController extends Controller
{
    public function preview(Request $request): JsonResponse
    {
        // Same officer capability as kick / set-main: any rank change
        // is a write that round-trips via GRM and needs an audit row.
        abort_unless(auth()->user()?->can('roster.kick'), 403);

        $validated = $request->validate([
            'op' => ['required', Rule::in([RankMacroBuilder::OP_PROMOTE, RankMacroBuilder::OP_DEMOTE])],
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
            // Rank changes only apply to active members. Anyone who's
            // already gone is surfaced as `skipped` so the officer
            // sees the reason instead of a silent drop.
            if ($m->status !== Member::STATUS_ACTIVE) {
                $skipped[] = [
                    'id' => $m->id,
                    'name' => $m->name,
                    'reason' => $m->status === Member::STATUS_BANNED ? 'banned' : 'no longer in guild',
                ];
                continue;
            }
            $charName = explode('-', $m->name, 2)[0];
            $characters[] = [
                'id' => $m->id,
                'name' => $m->name,
                'char_name' => $charName,
                'class' => $m->class,
            ];
            $names[] = $m->name;
        }

        $built = RankMacroBuilder::build($validated['op'], $names);

        return response()->json([
            'op' => $validated['op'],
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
            'op' => ['required', Rule::in([RankMacroBuilder::OP_PROMOTE, RankMacroBuilder::OP_DEMOTE])],
            'member_ids' => ['required', 'array', 'min:1'],
            'member_ids.*' => ['integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $actionType = $validated['op'] === RankMacroBuilder::OP_PROMOTE
            ? MemberAction::TYPE_PROMOTE_MACRO
            : MemberAction::TYPE_DEMOTE_MACRO;

        $members = Member::query()
            ->forGuild((string) config('grm.guild_key'))
            ->whereIn('id', $validated['member_ids'])
            ->where('status', Member::STATUS_ACTIVE)
            ->get();

        $logged = 0;
        foreach ($members as $m) {
            MemberAction::query()->create([
                'member_id' => $m->id,
                'reviewed_by_user_id' => auth()->id(),
                'action_type' => $actionType,
                'decision' => MemberAction::DECISION_ACCEPTED,
                'notes' => $validated['notes'] ?? null,
            ]);
            $logged++;
        }

        return response()->json(['logged' => $logged]);
    }
}
