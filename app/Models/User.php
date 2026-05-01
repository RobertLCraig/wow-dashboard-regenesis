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
    public const TIER_RAID_LEADER = 'raid_leader';

    public const DISPLAY_STANDARD = 'standard';
    public const DISPLAY_CLEAR = 'clear';
    public const DISPLAY_HIGH_CLARITY = 'high_clarity';

    public const DISPLAY_MODES = [
        self::DISPLAY_STANDARD,
        self::DISPLAY_CLEAR,
        self::DISPLAY_HIGH_CLARITY,
    ];

    public const THEME_DISCORD = 'discord';
    public const THEME_PHOENIX = 'phoenix';

    public const THEMES = [
        self::THEME_DISCORD,
        self::THEME_PHOENIX,
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'discord_id',
        'discord_username',
        'avatar_url',
        'tier',
        'team',
        'display_mode',
        'theme',
        'dashboard_layout',
        'discord_refresh_token',
        'last_role_check_at',
        'calendar_token',
        'google_refresh_token',
        'google_access_token',
        'google_token_expires_at',
        'google_calendar_id',
        'google_calendar_connected_at',
        'google_email',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'discord_refresh_token',
        'google_refresh_token',
        'google_access_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_role_check_at' => 'datetime',
            'password' => 'hashed',
            'dashboard_layout' => 'array',
            'google_token_expires_at' => 'datetime',
            'google_calendar_connected_at' => 'datetime',
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

    protected function googleRefreshToken(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? null : Crypt::decryptString($value),
            set: fn ($value) => $value === null ? null : Crypt::encryptString($value),
        );
    }

    protected function googleAccessToken(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? null : Crypt::decryptString($value),
            set: fn ($value) => $value === null ? null : Crypt::encryptString($value),
        );
    }

    /**
     * The single user (if any) currently authorised to push events to the
     * shared Google Calendar. The OAuth callback null-clears any other
     * row's google fields before persisting itself, so this should never
     * return more than one match. Ordered desc as belt-and-braces.
     */
    public static function googleConnector(): ?self
    {
        return self::query()
            ->whereNotNull('google_calendar_connected_at')
            ->orderByDesc('google_calendar_connected_at')
            ->first();
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
     * Holds any of the four authorised Discord tiers (gm, big6, officer,
     * raid_leader). Used by the registered Gates in AppServiceProvider;
     * v1 returns true for any tier, v2 may narrow per-Gate without
     * touching call sites.
     */
    public function isOfficerTier(): bool
    {
        return in_array($this->tier, [
            self::TIER_GM,
            self::TIER_BIG6,
            self::TIER_OFFICER,
            self::TIER_RAID_LEADER,
        ], true);
    }

    public function isAtLeast(string $tier): bool
    {
        $rank = [
            self::TIER_RAID_LEADER => 1,
            self::TIER_OFFICER => 2,
            self::TIER_BIG6 => 3,
            self::TIER_GM => 4,
        ];
        $mine = $rank[$this->tier] ?? 0;
        $needed = $rank[$tier] ?? 0;
        return $mine >= $needed;
    }
}
