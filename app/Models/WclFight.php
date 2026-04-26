<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WclFight extends Model
{
    public const DIFFICULTY_LFR     = 1;
    public const DIFFICULTY_NORMAL  = 3;
    public const DIFFICULTY_HEROIC  = 4;
    public const DIFFICULTY_MYTHIC  = 5;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kill' => 'boolean',
            'best_percentage' => 'decimal:2',
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'raw_json' => 'array',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(WclReport::class, 'wcl_report_id');
    }

    public function parses(): HasMany
    {
        return $this->hasMany(WclActorParse::class, 'wcl_fight_id');
    }

    public static function difficultyLabel(?int $d): string
    {
        return match ($d) {
            self::DIFFICULTY_LFR    => 'LFR',
            self::DIFFICULTY_NORMAL => 'Normal',
            self::DIFFICULTY_HEROIC => 'Heroic',
            self::DIFFICULTY_MYTHIC => 'Mythic',
            default => '?',
        };
    }
}
