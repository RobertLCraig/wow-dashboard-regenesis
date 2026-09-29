<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BisProfile extends Model
{
    /**
     * Every BiS source with its tab label, in the order the character page
     * falls through when no source is chosen: SimC keeps DPS on SimC, and
     * healers (no SimC rows) land on Wowhead, then the manual stubs.
     */
    public const SOURCES = [
        'simc' => 'SimC',
        'wowhead' => 'Wowhead',
        'manual' => 'Manual',
    ];

    protected $fillable = [
        'class',
        'spec',
        'hero_talent',
        'source',
        'profile_name',
        'source_path',
        'parsed_data',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'parsed_data' => 'array',
            'captured_at' => 'datetime',
        ];
    }
}
