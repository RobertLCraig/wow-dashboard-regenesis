<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BisProfile extends Model
{
    protected $fillable = [
        'class',
        'spec',
        'hero_talent',
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
