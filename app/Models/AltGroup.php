<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AltGroup extends Model
{
    protected $fillable = [
        'guild_key',
        'group_label',
        'main_member_id',
        'nickname',
        'time_modified',
    ];

    protected function casts(): array
    {
        return [
            'time_modified' => 'datetime',
        ];
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'alt_group_members')
            ->withPivot('is_main')
            ->withTimestamps();
    }

    public function directMembers(): HasMany
    {
        return $this->hasMany(Member::class);
    }
}
