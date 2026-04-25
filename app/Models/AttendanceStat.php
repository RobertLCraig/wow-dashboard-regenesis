<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceStat extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'time_filter_start' => 'datetime',
            'time_filter_end' => 'datetime',
            'attendance_pct' => 'decimal:2',
            'raw_json' => 'array',
        ];
    }
}
