<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
            'fights_imported_at' => 'datetime',
        ];
    }

    public function fights(): HasMany
    {
        return $this->hasMany(WclFight::class);
    }

    public function jumpUrl(): string
    {
        return "https://www.warcraftlogs.com/reports/{$this->code}";
    }
}
