<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RaidEvent extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'closing_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'advanced_settings_json' => 'array',
            'classes_json' => 'array',
            'roles_json' => 'array',
        ];
    }

    public function signups(): HasMany
    {
        return $this->hasMany(EventSignup::class);
    }

    public function discordJumpUrl(): string
    {
        return sprintf(
            'https://discord.com/channels/%s/%s/%s',
            $this->server_id,
            $this->channel_id,
            $this->raidhelper_event_id,
        );
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('starts_at', '>=', now())->orderBy('starts_at');
    }

    public function scopeWithinFeedWindow(Builder $query): Builder
    {
        return $query
            ->where('starts_at', '>=', now()->subDays(7))
            ->where('starts_at', '<=', now()->addDays(90));
    }
}
