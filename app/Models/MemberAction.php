<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberAction extends Model
{
    public const TYPE_PROMOTE = 'promote';
    public const TYPE_DEMOTE = 'demote';
    public const TYPE_KICK = 'kick';
    public const TYPE_SPECIAL = 'special';

    public const DECISION_ACCEPTED = 'accepted';
    public const DECISION_DISMISSED = 'dismissed';
    public const DECISION_SNOOZED = 'snoozed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'snooze_until' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
