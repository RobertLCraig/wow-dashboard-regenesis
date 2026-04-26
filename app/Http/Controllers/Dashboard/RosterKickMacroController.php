<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberAction;
use App\Support\KickMacroBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Two-step API powering the "Kick + alts" modal on the roster page.
 * Both endpoints are JSON, called from the modal via fetch.
 *
 * The dashboard never executes the kick. WoW has no inbound API, and
 * letting a web app rewrite a running client's SavedVariables is a
 * losing race. The officer copy-pastes the generated macro into the
 * game and runs it themselves; the next GRM ingest reflects the
 * actual roster change.
 *
 *   POST /roster/kick-macro
 *     body: { member_ids: [int, ...] }
 *     -> { macros: [str, ...], characters: [{id,name,class,status},...],
 *          skipped: [{id,name,reason}], oversized: [name,...] }
 *
 *   POST /roster/kick-macro/confirm
 *     body: { member_ids: [int, ...], notes?: str }
 *     -> { logged: int }
 */
class RosterKickMacroController extends Controller
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
            // Already gone or formally banned: macro shouldn't include them.
            // Officer might still want to see them in the modal, so we
            // surface them in `skipped` rather than filtering silently.
            if (in_array($m->status, [Member::STATUS_LEFT, Member::STATUS_BANNED], true)) {
                $skipped[] = [
                    'id' => $m->id,
                    'name' => $m->name,
                    'reason' => $m->status === Member::STATUS_BANNED ? 'already banned' : 'already left',
                ];
                continue;
            }
            $charName = $this->charName($m->name);
            $characters[] = [
                'id' => $m->id,
                'name' => $m->name,
                'char_name' => $charName,
                'class' => $m->class,
            ];
            $names[] = $charName;
        }

        $built = KickMacroBuilder::build($names);

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
            ->get();

        $logged = 0;
        foreach ($members as $m) {
            MemberAction::query()->create([
                'member_id' => $m->id,
                'reviewed_by_user_id' => auth()->id(),
                'action_type' => MemberAction::TYPE_KICK_MACRO,
                'decision' => MemberAction::DECISION_ACCEPTED,
                'notes' => $validated['notes'] ?? null,
            ]);
            $logged++;
        }

        return response()->json(['logged' => $logged]);
    }

    /**
     * `members.name` is "Char-Realm" (GRM convention). The /gremove
     * macro takes the character name only.
     */
    private function charName(string $memberName): string
    {
        return explode('-', $memberName, 2)[0];
    }
}
