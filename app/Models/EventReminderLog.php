<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventReminderLog extends Model
{
    protected $table = 'event_reminder_log';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
        ];
    }
}
