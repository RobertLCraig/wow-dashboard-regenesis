<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot row between a team slug and a discord_roles entry. One row
 * per (team_slug, discord_role_id) pair. The team_slug references
 * config('raidhelper.teams') keys (heroic / mythic / keynight / social)
 * - teams themselves aren't a DB entity since they're a static product
 * concept, not officer-managed.
 */
class TeamRoleMention extends Model
{
    protected $guarded = ['id'];

    public function role(): BelongsTo
    {
        return $this->belongsTo(DiscordRole::class, 'discord_role_id');
    }
}
