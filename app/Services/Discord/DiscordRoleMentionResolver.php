<?php

namespace App\Services\Discord;

use App\Models\DiscordRole;
use App\Models\TeamRoleMention;
use Illuminate\Support\Collection;

/**
 * Single source of truth for "which Discord roles get pinged on
 * events for team X". Reads from team_role_mentions joined onto
 * discord_roles; the team list itself stays in config since teams
 * (heroic / mythic / keynight / social) are a static product
 * concept rather than officer-managed.
 *
 * Roles with an empty discord_id are filtered out at API-payload
 * time but kept for display, so the admin UI can show "configured
 * but not yet linked to a Discord snowflake" rows distinctly from
 * "fully wired" rows.
 */
class DiscordRoleMentionResolver
{
    /**
     * Roles attached to a team, ordered by position. Returns every
     * row (incl. ones with no snowflake yet) so the admin UI and
     * form previews can render them.
     *
     * @return Collection<int, DiscordRole>
     */
    public static function forTeam(string $slug): Collection
    {
        return DiscordRole::query()
            ->join('team_role_mentions', 'team_role_mentions.discord_role_id', '=', 'discord_roles.id')
            ->where('team_role_mentions.team_slug', $slug)
            ->orderBy('team_role_mentions.position')
            ->select('discord_roles.*')
            ->get();
    }

    /**
     * Display names of every pingable role attached to the team (in order).
     * "Pingable" means the role has a discord_id snowflake configured.
     * Used by the EventController to build the API `mentions` value
     * (Raid-Helper expects `advancedSettings.mentions` as a
     * comma-separated role-name string), and by the create form
     * previews to render the "Will ping" hint.
     *
     * Roles with no snowflake are kept in the DB (admin UI shows them as
     * "not yet linked") but intentionally excluded here so they don't
     * end up in the API payload or pre-fill an invalid mentions string.
     *
     * @return list<string>
     */
    public static function namesForTeam(string $slug): array
    {
        return self::forTeam($slug)
            ->filter(fn ($r) => ! empty($r->discord_id))
            ->pluck('name')
            ->values()
            ->all();
    }

    /**
     * Names of all pingable roles in the system, regardless of team.
     * Passed to the full event-creation form as a datalist so the
     * officer gets autocomplete on the free-text mentions input.
     *
     * @return list<string>
     */
    public static function allPingableNames(): array
    {
        return DiscordRole::query()
            ->pingable()
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    /**
     * Map of {channel_id: [role names]} for every team configured in
     * config('raidhelper.teams'). Powers the live "Will ping" hint on
     * the full event-creation form: officer changes channel, hint
     * updates without a server round-trip.
     *
     * @return array<string, list<string>>
     */
    public static function namesByChannelId(): array
    {
        $out = [];
        foreach ((array) config('raidhelper.teams', []) as $slug => $team) {
            $cid = $team['channel_id'] ?? null;
            if (! $cid) {
                continue;
            }
            $names = self::namesForTeam($slug);
            if (! empty($names)) {
                $out[$cid] = $names;
            }
        }
        return $out;
    }

    /**
     * All (team_slug, role_id) pairings currently in the DB. Keyed by
     * team_slug, value is an array of role ids in position order.
     * Used by the admin page to render checkboxes in their saved state.
     *
     * @return array<string, list<int>>
     */
    public static function assignmentsByTeam(): array
    {
        return TeamRoleMention::query()
            ->orderBy('team_slug')
            ->orderBy('position')
            ->get(['team_slug', 'discord_role_id'])
            ->groupBy('team_slug')
            ->map(fn ($rows) => $rows->pluck('discord_role_id')->map(fn ($id) => (int) $id)->all())
            ->all();
    }
}
