<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WclReport extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'captured_at' => 'datetime',
            'raw_json' => 'array',
        ];
    }

    public function jumpUrl(): string
    {
        return "https://www.warcraftlogs.com/reports/{$this->code}";
    }
}
