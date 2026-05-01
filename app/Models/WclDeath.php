<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WclDeath extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'raw_json' => 'array',
            'death_amount' => 'integer',
            'overkill_amount' => 'integer',
            'death_time_ms' => 'integer',
            'killing_ability_id' => 'integer',
        ];
    }

    public function fight(): BelongsTo
    {
        return $this->belongsTo(WclFight::class, 'wcl_fight_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
