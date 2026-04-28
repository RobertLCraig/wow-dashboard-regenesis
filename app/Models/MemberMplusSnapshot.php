<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberMplusSnapshot extends Model
{
    protected $table = 'member_mplus_snapshots';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'mythic_rating' => 'float',
            'current_period_runs' => 'array',
            'seasons' => 'array',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(Snapshot::class);
    }
}
