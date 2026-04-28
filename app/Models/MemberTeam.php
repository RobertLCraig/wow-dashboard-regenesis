<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One pivot row per (member, team). Read-side queries use Member's
 * teams() relationship + onAnyTeam() scope; the resolver and admin
 * controller write directly through this model.
 *
 * is_override = true means an officer set this team manually (sticky
 * across rank changes); false means it was derived from the in-game rank
 * by TeamResolver (rebuilt on every recompute when no overrides exist).
 */
class MemberTeam extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_override' => 'boolean',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by_user_id');
    }
}
