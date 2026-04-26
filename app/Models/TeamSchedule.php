<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-team raid schedule override. One row per team slug. Edited via
 * /admin/teams/schedule. Read by TeamScheduleResolver, which merges
 * these values onto the config('raidhelper.teams.{slug}') defaults.
 */
class TeamSchedule extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'raid_days' => 'array',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
