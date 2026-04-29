<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Officer-managed Discord linkage on a Member row. Pure dashboard
 * state: there is no GRM/WoW round-trip for this field, because the
 * Discord-to-character mapping has no in-game equivalent.
 *
 * One Discord user can own many characters (a player's main + alts all
 * share the same snowflake), so this is a 1-to-N relationship from
 * users.discord_id to members.discord_user_id. The schema enforces
 * "at most one Discord user per character" via the single column on
 * members; bulk linkage of an alt group is the caller's job.
 *
 *   PUT  /roster/{member}/discord-link  body: { discord_user_id, discord_username }
 *   DELETE /roster/{member}/discord-link
 *
 * Both endpoints return the updated link as JSON so the modal can
 * reflect the new state without a full page reload.
 */
class MemberDiscordLinkController extends Controller
{
    public function update(Request $request, Member $member): JsonResponse
    {
        abort_unless(auth()->user()?->can('roster.view'), 403);
        $this->assertOwnedByGuild($member);

        $validated = $request->validate([
            // Discord snowflakes are 17-20 numeric digits. Accept the
            // full range so a future epoch shift (Discord has bumped
            // the snowflake structure once) doesn't trip validation.
            'discord_user_id' => ['nullable', 'string', 'regex:/^\d{17,20}$/'],
            // Username slot is generous - Discord caps the global
            // username at 32 but server nicknames go to 32 too, and we
            // store whatever the officer typed. A small buffer is fine.
            'discord_username' => ['nullable', 'string', 'max:64'],
        ], [
            'discord_user_id.regex' => 'Discord user IDs are 17 to 20 numeric digits (the snowflake from Discord, not the username).',
        ]);

        $userId = $this->blankToNull($validated['discord_user_id'] ?? null);
        $username = $this->blankToNull($validated['discord_username'] ?? null);

        // Both blank is a no-op rather than a clear: explicit clear is
        // the DELETE verb. Returning the current state gives the modal
        // a clean confirmation either way.
        if ($userId === null && $username === null) {
            return response()->json($this->payload($member));
        }

        $member->forceFill([
            'discord_user_id' => $userId,
            'discord_username' => $username,
            'discord_link_source' => Member::DISCORD_LINK_MANUAL,
            'discord_linked_at' => now(),
            'discord_linked_by_user_id' => auth()->id(),
        ])->save();

        return response()->json($this->payload($member->fresh()));
    }

    public function destroy(Member $member): JsonResponse
    {
        abort_unless(auth()->user()?->can('roster.view'), 403);
        $this->assertOwnedByGuild($member);

        $member->forceFill([
            'discord_user_id' => null,
            'discord_username' => null,
            'discord_link_source' => null,
            'discord_linked_at' => null,
            'discord_linked_by_user_id' => null,
        ])->save();

        return response()->json($this->payload($member->fresh()));
    }

    private function assertOwnedByGuild(Member $member): void
    {
        // Guard against cross-guild member ID stuffing once we host
        // more than one guild. Free belt-and-braces today.
        abort_unless($member->guild_key === (string) config('grm.guild_key'), 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Member $member): array
    {
        return [
            'member' => [
                'id' => $member->id,
                'name' => $member->name,
                'class' => $member->class,
            ],
            'link' => [
                'discord_user_id' => $member->discord_user_id,
                'discord_username' => $member->discord_username,
                'source' => $member->discord_link_source,
                'linked_at' => $member->discord_linked_at?->toIso8601String(),
                'linked_by_user_id' => $member->discord_linked_by_user_id,
            ],
        ];
    }

    private function blankToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}
