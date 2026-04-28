<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberAction;
use App\Support\UnlinkAltMacroBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Two-step API powering the "Unlink from alt group" modal on the
 * roster page. Generates `/run GRM.RemovePlayerFromAltGroup(...)`
 * lines for one or more characters.
 *
 *   POST /roster/unlink-alt
 *     body: { member_ids: [int, ...] }
 *     -> { macros, characters, skipped, oversized }
 *
 *   POST /roster/unlink-alt/confirm
 *     body: { member_ids: [int, ...], notes?: str }
 *     -> { logged: int }
 */
class RosterUnlinkAltMacroController extends Controller
{
    public function preview(Request $request): JsonResponse
    {
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
            // Unlink only applies to active members already in a group.
            // Banned/left members are surfaced as `skipped` so the
            // officer sees the reason rather than a silent drop.
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

        $built = UnlinkAltMacroBuilder::build($names);

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
                'action_type' => MemberAction::TYPE_UNLINK_ALT_MACRO,
                'decision' => MemberAction::DECISION_ACCEPTED,
                'notes' => $validated['notes'] ?? null,
            ]);
            $logged++;
        }

        return response()->json(['logged' => $logged]);
    }
}
