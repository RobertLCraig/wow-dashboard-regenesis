<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A pingable Discord role. Edited via /admin/discord-roles, attached
 * to teams via team_role_mentions. Used by EventController to build
 * the Raid-Helper `mentions` string when creating events.
 */
class DiscordRole extends Model
{
    protected $guarded = ['id'];

    public function teamMentions(): HasMany
    {
        return $this->hasMany(TeamRoleMention::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * Roles that have a usable snowflake. Used at mention-build time
     * so half-configured rows don't end up in the API payload.
     */
    public function scopePingable($query)
    {
        return $query->whereNotNull('discord_id')->where('discord_id', '!=', '');
    }
}
