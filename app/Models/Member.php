<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Member extends Model
{
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_LEFT = 'left';
    public const STATUS_BANNED = 'banned';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'join_date' => 'date',
            'birthday' => 'date',
            'last_online_at' => 'datetime',
            'banned_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_blizzard_seen_at' => 'datetime',
            'is_online' => 'boolean',
            'is_mobile' => 'boolean',
            'join_date_unknown' => 'boolean',
            'hardcore_is_dead' => 'boolean',
            'recommend_promote' => 'boolean',
            'recommend_demote' => 'boolean',
            'recommend_kick' => 'boolean',
            'recommend_special' => 'boolean',
            'is_valid_at_blizzard' => 'boolean',
        ];
    }

    public function altGroup(): BelongsTo
    {
        return $this->belongsTo(AltGroup::class);
    }

    public function main(): BelongsTo
    {
        return $this->belongsTo(self::class, 'main_member_id');
    }

    public function alts(): HasMany
    {
        return $this->hasMany(self::class, 'main_member_id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(MemberSnapshot::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(MemberEvent::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(MemberAction::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeForGuild(Builder $query, string $guildKey): Builder
    {
        return $query->where('guild_key', $guildKey);
    }

    public function scopeOnTeam(Builder $query, string $team): Builder
    {
        return $query->where('team', $team);
    }
}
