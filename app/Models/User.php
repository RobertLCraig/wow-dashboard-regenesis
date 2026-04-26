<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const TIER_GM = 'gm';
    public const TIER_BIG6 = 'big6';
    public const TIER_OFFICER = 'officer';

    protected $fillable = [
        'name',
        'email',
        'password',
        'discord_id',
        'discord_username',
        'avatar_url',
        'tier',
        'team',
        'discord_refresh_token',
        'last_role_check_at',
        'calendar_token',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'discord_refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_role_check_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Encrypt the Discord refresh token at rest. We never need to read it
     * outside of the role-recheck path, so cost of crypt on every save is
     * negligible.
     */
    protected function discordRefreshToken(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? null : Crypt::decryptString($value),
            set: fn ($value) => $value === null ? null : Crypt::encryptString($value),
        );
    }

    /**
     * Lazily mint a calendar token on first access. Stable per user; can
     * be regenerated from a settings page button to invalidate a leaked
     * webcal:// URL.
     */
    public function ensureCalendarToken(): string
    {
        if (! $this->calendar_token) {
            $this->forceFill(['calendar_token' => Str::random(64)])->save();
        }
        return $this->calendar_token;
    }

    /**
     * Holds any of the three Discord officer tiers. Used by the
     * registered Gates in AppServiceProvider; v1 returns true for any
     * tier, v2 may narrow per-Gate without touching call sites.
     */
    public function isOfficerTier(): bool
    {
        return in_array($this->tier, [self::TIER_GM, self::TIER_BIG6, self::TIER_OFFICER], true);
    }

    public function isAtLeast(string $tier): bool
    {
        $rank = [self::TIER_OFFICER => 1, self::TIER_BIG6 => 2, self::TIER_GM => 3];
        $mine = $rank[$this->tier] ?? 0;
        $needed = $rank[$tier] ?? 0;
        return $mine >= $needed;
    }
}
