<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Snapshot extends Model
{
    public const SOURCE_GRM = 'grm';
    public const SOURCE_WOWAUDIT = 'wowaudit';
    public const SOURCE_RAIDERIO = 'raiderio';
    public const SOURCE_BLIZZARD = 'blizzard';
    public const SOURCE_BLIZZARD_EQUIPMENT = 'blizzard_equipment';

    protected $fillable = [
        'guild_key',
        'captured_at',
        'source',
        'payload_hash',
        'member_count',
        'raw_path',
        'grm_version',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
        ];
    }

    public function memberSnapshots(): HasMany
    {
        return $this->hasMany(MemberSnapshot::class);
    }

    public function memberEvents(): HasMany
    {
        return $this->hasMany(MemberEvent::class);
    }
}
