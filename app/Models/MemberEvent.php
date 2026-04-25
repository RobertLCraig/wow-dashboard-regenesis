<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberEvent extends Model
{
    public const TYPE_JOINED = 'joined';
    public const TYPE_RETURNED = 'returned';
    public const TYPE_LEFT = 'left';
    public const TYPE_KICKED = 'kicked';
    public const TYPE_BANNED = 'banned';
    public const TYPE_PROMOTED = 'promoted';
    public const TYPE_DEMOTED = 'demoted';
    public const TYPE_LEVEL_UP = 'level_up';
    public const TYPE_NOTE_CHANGED = 'note_changed';
    public const TYPE_MARKED_FOR_PROMOTE = 'marked_for_promote';
    public const TYPE_MARKED_FOR_DEMOTE = 'marked_for_demote';
    public const TYPE_MARKED_FOR_KICK = 'marked_for_kick';
    public const TYPE_BECAME_INACTIVE_30D = 'became_inactive_30d';
    public const TYPE_ANNIVERSARY = 'anniversary';

    protected $fillable = [
        'member_id',
        'snapshot_id',
        'type',
        'payload_json',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'occurred_at' => 'datetime',
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

    public function scopeOfType(Builder $query, string|array $type): Builder
    {
        return $query->whereIn('type', (array) $type);
    }

    public function scopeSince(Builder $query, \DateTimeInterface $since): Builder
    {
        return $query->where('occurred_at', '>=', $since);
    }
}
