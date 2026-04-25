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
        //
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
