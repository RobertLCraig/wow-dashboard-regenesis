<?php

namespace App\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // RaidHelperClient and WowauditClient have string constructor
        // params (api keys) the container can't auto-resolve. Bind both
        // to their fromConfig() factories so type-hinted method
        // injection in controllers / commands "just works", and tests
        // that override config() before resolving get the right values.
        $this->app->scoped(\App\Services\RaidHelper\RaidHelperClient::class,
            fn () => \App\Services\RaidHelper\RaidHelperClient::fromConfig());
        $this->app->scoped(\App\Services\Wowaudit\WowauditClient::class,
            fn () => \App\Services\Wowaudit\WowauditClient::fromConfig());
    }

    public function boot(): void
    {
        // Register the Discord provider with Socialite. The
        // SocialiteProviders\Manager package emits SocialiteWasCalled and
        // each provider listens for it to lazy-extend the Socialite
        // factory. Doing this in boot() keeps it close to where the
        // related services config lives, rather than tucked in a
        // listener class for one provider.
        Event::listen(SocialiteWasCalled::class, [
            \SocialiteProviders\Discord\DiscordExtendSocialite::class,
            'handle',
        ]);

        $this->registerGates();
    }

    /**
     * Officer-tier permission Gates.
     *
     * v1 every gate returns true if the user has any of the three Discord
     * roles (gm / big6 / officer) per the user's "flat now, granular
     * later" preference (see feedback_permissions memory). When narrowing
     * later, swap the closure body for the right tier check; call sites
     * stay the same.
     */
    private function registerGates(): void
    {
        $anyOfficerTier = fn ($user) => $user !== null && $user->isOfficerTier();

        foreach ([
            'dashboard.view',
            'dashboard.general.view',     // sidebar: General Guild Management
            'dashboard.team.heroic.view', // sidebar: Heroic Team (raid leaders later)
            'dashboard.team.mythic.view', // sidebar: Mythic Team (raid leaders later)
            'dashboard.keynight.view',    // sidebar: Keynight (M+ leaders later)
            'events.create',
            'events.edit',
            'events.delete',
            'roster.view',
            'members.edit',
            'members.review',          // accept/dismiss action queue items
            'bans.manage',
            'attendance.view',
            'calendar.subscribe',
            'settings.manage',         // GM-only later
            'apikeys.rotate',          // GM-only later
        ] as $ability) {
            Gate::define($ability, $anyOfficerTier);
        }
    }
}
