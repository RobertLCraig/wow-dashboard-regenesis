<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventSignup extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'signed_up_at' => 'datetime',
            'is_fake' => 'boolean',
        ];
    }

    public function raidEvent(): BelongsTo
    {
        return $this->belongsTo(RaidEvent::class);
    }
}
