<?php

namespace App\Providers;

use App\Models\RaidEvent;
use App\Observers\RaidEventObserver;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
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
        $this->app->scoped(\App\Services\Blizzard\BlizzardClient::class,
            fn () => \App\Services\Blizzard\BlizzardClient::fromConfig());

        // The dictionary loads two JSON files lazily on first lookup.
        // scoped() so the cached arrays live for the duration of one
        // request / command and don't leak across tests.
        $this->app->scoped(\App\Support\WowDictionary::class,
            fn () => new \App\Support\WowDictionary());
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

        // Observe raid events so every write path (officer create,
        // officer destroy, Raid-Helper webhook create/update/delete,
        // daily raidhelper:sync-events backfill) dispatches a Google
        // Calendar push job. Short-circuits inside the job when no
        // officer is connected.
        RaidEvent::observe(RaidEventObserver::class);

        $this->registerScheduleAuditLog();
        $this->registerGates();
    }

    /**
     * Per-tick scheduler audit trail to storage/logs/cron.log.
     *
     * Hostinger's hPanel cron wrapper swallows shell-level >> redirects,
     * so the cron line cannot point its stdout at a file the way every
     * tutorial says to. Wire Laravel's own ScheduledTask* events
     * straight at the cron channel instead - independent of how the
     * cron command is configured at the OS level.
     */
    private function registerScheduleAuditLog(): void
    {
        Event::listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $e): void {
            Log::channel('cron')->info('starting', [
                'cmd' => $e->task->command ?? $e->task->description,
            ]);
        });

        Event::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $e): void {
            Log::channel('cron')->info('finished', [
                'cmd' => $e->task->command ?? $e->task->description,
                'runtime_s' => round($e->runtime, 2),
            ]);
        });

        Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $e): void {
            Log::channel('cron')->error('failed', [
                'cmd' => $e->task->command ?? $e->task->description,
                'exception' => $e->exception?->getMessage(),
            ]);
        });

        // ScheduledTaskSkipped fires when withoutOverlapping / onOneServer
        // refuses the run. Worth knowing about - that's exactly the
        // failure mode that masked itself for hours yesterday.
        Event::listen(ScheduledTaskSkipped::class, function (ScheduledTaskSkipped $e): void {
            Log::channel('cron')->warning('skipped (mutex held)', [
                'cmd' => $e->task->command ?? $e->task->description,
            ]);
        });
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
            'dashboard.social.view',      // sidebar: Social (open to all members later)
            'events.create',
            'events.edit',
            'events.delete',
            'roster.view',
            'roster.kick',             // build the /gremove + alts macro
            'reports.view',            // /reports - WCL parses + fight history
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
