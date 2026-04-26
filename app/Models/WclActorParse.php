<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WclActorParse extends Model
{
    public const ROLE_TANK   = 'tank';
    public const ROLE_HEALER = 'healer';
    public const ROLE_DPS    = 'dps';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'metric_per_second' => 'decimal:1',
            'raw_json' => 'array',
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
