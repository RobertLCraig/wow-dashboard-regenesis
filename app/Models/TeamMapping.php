<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (source, key) pair that an officer has classified into a
 * team (or explicitly cleared). Edited via /admin/teams.
 *
 * Source: 'grm_rank' for in-game rank names, 'discord_role' for Discord
 * role snowflakes. Adding a future source (wowaudit team id, raider.io
 * guild rank, etc.) doesn't require touching this model.
 */
class TeamMapping extends Model
{
    public const SOURCE_GRM_RANK = 'grm_rank';
    public const SOURCE_DISCORD_ROLE = 'discord_role';

    public const TEAM_MYTHIC = 'mythic';
    public const TEAM_MYTHIC_TRIAL = 'mythic_trial';
    public const TEAM_HEROIC = 'heroic';
    public const TEAM_HEROIC_TRIAL = 'heroic_trial';

    /** @var list<string> */
    public const TEAMS = [
        self::TEAM_MYTHIC,
        self::TEAM_MYTHIC_TRIAL,
        self::TEAM_HEROIC,
        self::TEAM_HEROIC_TRIAL,
    ];

    /** @var list<string> */
    public const SOURCES = [
        self::SOURCE_GRM_RANK,
        self::SOURCE_DISCORD_ROLE,
    ];

    protected $guarded = ['id'];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * Display label for a team value. Null/unknown -> human-friendly
     * "Unassigned".
     */
    public static function teamLabel(?string $team): string
    {
        return match ($team) {
            self::TEAM_MYTHIC => 'Mythic',
            self::TEAM_MYTHIC_TRIAL => 'Mythic Trial',
            self::TEAM_HEROIC => 'Heroic',
            self::TEAM_HEROIC_TRIAL => 'Heroic Trial',
            default => 'Unassigned',
        };
    }

    /**
     * The highest raid difficulty a team meaningfully runs as a unit.
     * Used to cap the team's headline progression so a Heroic team
     * doesn't display "4/9 M" just because one member happens to have
     * picked up some Mythic kills with the Mythic team.
     *
     * Returns 'mythic' | 'heroic' | 'normal'. Unknown teams default to
     * 'mythic' (no cap) so callers stay forwards-compatible.
     */
    public static function maxDifficultyFor(?string $team): string
    {
        return match ($team) {
            self::TEAM_MYTHIC, self::TEAM_MYTHIC_TRIAL => 'mythic',
            self::TEAM_HEROIC, self::TEAM_HEROIC_TRIAL => 'heroic',
            default => 'mythic',
        };
    }
}
