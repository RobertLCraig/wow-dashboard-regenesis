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
    // Audit-only: officer generated (and confirmed) a kick-all-alts macro.
    // Doesn't change member status; the next GRM ingest does that once
    // the macro actually runs in-game.
    public const TYPE_KICK_MACRO = 'kick_macro';
    // Audit-only: officer generated (and confirmed) a /run GRM.SetMain
    // macro. Same pattern: the dashboard never mutates GRM data, the
    // ingest catches up after the macro runs in-game.
    public const TYPE_SET_MAIN_MACRO = 'set_main_macro';

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
