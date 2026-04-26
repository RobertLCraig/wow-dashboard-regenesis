<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * One configured Discord webhook URL the dashboard can post to.
 *
 * Purposes are kept as plain strings so adding a new sender (e.g.
 * 'parse_alert' once WCL ingest lands) doesn't require a migration -
 * just add a constant + label below.
 */
class DiscordWebhook extends Model
{
    public const PURPOSE_WEEKLY_DIGEST   = 'weekly_digest';
    public const PURPOSE_EVENT_ANNOUNCE  = 'event_announce';
    public const PURPOSE_EVENT_REMINDER  = 'event_reminder';
    public const PURPOSE_TEAM_NEWS       = 'team_news';

    /**
     * Known purposes with display labels and a short hint for the admin
     * UI dropdown. Officers can still enter a freeform purpose if they
     * need to wire something not on this list.
     *
     * @var array<string, array{label:string, hint:string}>
     */
    public const PURPOSES = [
        self::PURPOSE_WEEKLY_DIGEST  => ['label' => 'Weekly digest',   'hint' => 'Sunday officer summary'],
        self::PURPOSE_EVENT_ANNOUNCE => ['label' => 'Event announce',  'hint' => 'New / changed Raid-Helper events'],
        self::PURPOSE_EVENT_REMINDER => ['label' => 'Event reminder',  'hint' => 'Pre-raid pings'],
        self::PURPOSE_TEAM_NEWS      => ['label' => 'Team news',       'hint' => 'Roster changes for this team'],
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_posted_at' => 'datetime',
        ];
    }

    /**
     * Encrypt the webhook URL at rest. Symmetric cost vs. the value of
     * not leaking a posting token if someone reads the raw table.
     */
    protected function url(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? null : Crypt::decryptString($value),
            set: fn ($value) => $value === null ? null : Crypt::encryptString($value),
        );
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    public function scopeForPurpose(Builder $query, string $purpose): Builder
    {
        return $query->where('purpose', $purpose);
    }

    public static function purposeLabel(string $purpose): string
    {
        return self::PURPOSES[$purpose]['label'] ?? ucwords(str_replace('_', ' ', $purpose));
    }
}
