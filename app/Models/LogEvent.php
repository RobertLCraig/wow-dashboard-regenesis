<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LogEvent extends Model
{
    protected $fillable = [
        'guild_key',
        'occurred_at',
        'type_code',
        'type_name',
        'actor',
        'target',
        'message_raw',
        'raw_json',
        'dedup_hash',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'raw_json' => 'array',
        ];
    }

    /**
     * Strip WoW colour codes (|cffXXXXXX...|r) from the rendered message
     * so the timeline widget shows readable text.
     */
    public function plainMessage(): string
    {
        return preg_replace('/\|c[0-9a-fA-F]{8}|\|r/', '', (string) $this->message_raw) ?? '';
    }
}
