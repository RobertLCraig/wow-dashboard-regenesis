<?php

use App\Http\Controllers\Admin\BlizzardSyncController;
use App\Http\Controllers\Admin\DiscordRoleConfigController;
use App\Http\Controllers\Admin\DiscordWebhookController;
use App\Http\Controllers\Admin\GoogleCalendarSettingsController;
use App\Http\Controllers\Admin\RaiderioSyncController;
use App\Http\Controllers\Admin\SyncDashboardController;
use App\Http\Controllers\Admin\TeamMappingController;
use App\Http\Controllers\Admin\TeamScheduleController;
use App\Http\Controllers\Admin\WclSyncController;
use App\Http\Controllers\Admin\WowauditSyncController;
use App\Http\Controllers\Auth\DiscordController;
use App\Http\Controllers\Auth\GoogleCalendarController;
use App\Http\Controllers\Calendar\IcsController;
use App\Http\Controllers\Dashboard\CharacterController;
use App\Http\Controllers\Dashboard\CharacterTeamOverrideController;
use App\Http\Controllers\Dashboard\CompositionController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Dashboard\FarmPlannerController;
use App\Http\Controllers\Dashboard\KeynightController;
use App\Http\Controllers\Dashboard\MemberActionController;
use App\Http\Controllers\Dashboard\MemberDiscordLinkController;
use App\Http\Controllers\Dashboard\ReportsController;
use App\Http\Controllers\Dashboard\RosterAddAltMacroController;
use App\Http\Controllers\Dashboard\RosterController;
use App\Http\Controllers\Dashboard\RosterCustomNoteMacroController;
use App\Http\Controllers\Dashboard\RosterKickMacroController;
use App\Http\Controllers\Dashboard\RosterRankMacroController;
use App\Http\Controllers\Dashboard\RosterSetMainMacroController;
use App\Http\Controllers\Dashboard\RosterUnlinkAltMacroController;
use App\Http\Controllers\Dashboard\SocialController;
use App\Http\Controllers\Dashboard\TeamDashboardController;
use App\Http\Controllers\Dashboard\TrialPipelineController;
use App\Http\Controllers\Events\EventController;
use App\Http\Controllers\PreferencesController;
use App\Http\Middleware\RequireTier;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::view('/', 'landing')->name('landing');

Route::view('/auth/discord/unauthorised', 'auth.unauthorised')->name('auth.discord.unauthorised');
Route::view('/auth/discord/failed', 'auth.failed')->name('auth.discord.failed');

Route::get('/auth/discord', [DiscordController::class, 'start'])->name('auth.discord.start');
Route::get('/auth/discord/callback', [DiscordController::class, 'callback'])->name('auth.discord.callback');
Route::get('/auth/discord/bot-installed', [DiscordController::class, 'botInstalled'])->name('auth.discord.bot-installed');
Route::post('/logout', [DiscordController::class, 'logout'])->middleware('auth')->name('logout');

// Guild-wide surface. THIS GROUP IS THE WHOLE LIST of pages an ordinary
// guild member can reach: Social (the guild-wide events hub the member
// tier exists for) and the read-only Roster views. Everything else is
// officer-only by default - a page is opened to members by moving it in
// here on purpose, never by forgetting to gate it below.
Route::middleware(['auth', RequireTier::class.':'.User::TIER_MEMBER])->group(function () {
    // Social hub: guild-wide events calendar. Aggregates Raid-Helper
    // events with computed world events (Darkmoon Faire, holidays).
    // Read-only - event creation lives on /events for officers.
    Route::get('/dashboard/social', [SocialController::class, 'index'])->name('dashboard.social');

    // Searchable + filterable consolidated roster. Read-only: the kick /
    // rank / note macro endpoints below stay officer-only, and the page
    // hides those controls behind the roster.kick gate.
    Route::get('/roster', [RosterController::class, 'index'])->name('roster.index');
    Route::get('/roster.csv', [RosterController::class, 'csv'])->name('roster.csv');

    // Per-user display preferences (clarity dial, theme picker). Every
    // signed-in page's sidebar carries these forms, members' included.
    // Single POST endpoint per pref keeps the surface tiny and JS-free.
    Route::post('/preferences/display', [PreferencesController::class, 'display'])->name('preferences.display');
    Route::post('/preferences/theme', [PreferencesController::class, 'theme'])->name('preferences.theme');
});

// Officer-only application surface. Every other dashboard route lives
// behind auth + RequireTier (raid leader and up by default) so a removed Discord
// role takes effect within the configured cache TTL without requiring a
// re-login.
Route::middleware(['auth', RequireTier::class])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Recruitment pipeline: Applied / Trial / Raider / Alumni, read off
    // recruit forms, member_teams and team_joined/team_left events.
    Route::get('/roster/pipeline', TrialPipelineController::class)->name('pipeline.index');
    Route::post('/dashboard/members/{member}/actions', [MemberActionController::class, 'store'])->name('dashboard.member.actions.store');

    // Team-scoped dashboards. Same widgets as /dashboard but filtered to
    // the team's members + the team's raid signup channel, plus a
    // quick-create panel for raid leaders.
    Route::get('/dashboard/heroic', [TeamDashboardController::class, 'heroic'])->name('dashboard.team.heroic');
    Route::get('/dashboard/mythic', [TeamDashboardController::class, 'mythic'])->name('dashboard.team.mythic');

    // Keynight (organised M+) is its own activity, not a raid team page.
    // Standalone page so heroic + mythic raiders both find it in one place.
    Route::get('/dashboard/keynight', [KeynightController::class, 'index'])->name('dashboard.keynight');

    // Composition planner per team. Aggregates the WCL parse data into
    // a role-grouped view (tank / healer / melee / ranged) so a raid
    // lead can see who their strongest at each role over a window.
    Route::get('/composition/{team}', [CompositionController::class, 'show'])
        ->where('team', 'heroic|mythic')->name('composition.show');

    // Farm-event planner. Pick a mount/pet/toy by Blizzard id and see
    // who already has it. Reads the latest member_social_snapshots
    // (refreshed weekly by blizzard:pull-social).
    Route::get('/farm-planner', [FarmPlannerController::class, 'index'])->name('farm-planner.index');

    // Kick + alts macro generator. preview() returns the JSON the modal
    // renders; confirm() logs MemberAction rows for the audit trail
    // after the officer says they ran the macro in-game.
    Route::post('/roster/kick-macro', [RosterKickMacroController::class, 'preview'])->name('roster.kick-macro.preview');
    Route::post('/roster/kick-macro/confirm', [RosterKickMacroController::class, 'confirm'])->name('roster.kick-macro.confirm');

    // /run GRM.SetMain(...) macro generator. Fixes drifted "main"
    // designations that the dashboard can't mutate directly because
    // GRM data lives in the WoW client. Same preview / confirm shape
    // as the kick-macro flow.
    Route::post('/roster/set-main', [RosterSetMainMacroController::class, 'preview'])->name('roster.set-main.preview');
    Route::post('/roster/set-main/confirm', [RosterSetMainMacroController::class, 'confirm'])->name('roster.set-main.confirm');

    // /gpromote and /gdemote macro generator. Single endpoint, the
    // op is in the request body so the modal can swap between the
    // two without re-routing.
    Route::post('/roster/rank-macro', [RosterRankMacroController::class, 'preview'])->name('roster.rank-macro.preview');
    Route::post('/roster/rank-macro/confirm', [RosterRankMacroController::class, 'confirm'])->name('roster.rank-macro.confirm');

    // /run GRM_API.EditCustomNote(...) macro generator. Targets GRM's
    // own custom-note slot, never the Blizzard Public/Officer notes.
    // Per-member; the modal sends the typed note + replace flag and
    // gets back a single macro line + the current note for context.
    Route::post('/roster/custom-note', [RosterCustomNoteMacroController::class, 'preview'])->name('roster.custom-note.preview');
    Route::post('/roster/custom-note/confirm', [RosterCustomNoteMacroController::class, 'confirm'])->name('roster.custom-note.confirm');

    // /run GRM.RemovePlayerFromAltGroup(...) macro generator. Used to
    // break a wrong alt link, e.g. if GRM has linked someone to the
    // wrong group.
    Route::post('/roster/unlink-alt', [RosterUnlinkAltMacroController::class, 'preview'])->name('roster.unlink-alt.preview');
    Route::post('/roster/unlink-alt/confirm', [RosterUnlinkAltMacroController::class, 'confirm'])->name('roster.unlink-alt.confirm');

    // Discord linkage on a member row. Pure dashboard state (no GRM
    // round-trip), so this writes the columns directly. PUT updates,
    // DELETE clears. Officers fill these in by hand for now; a future
    // resolver will translate username-only entries into snowflakes.
    Route::put('/roster/{member}/discord-link', [MemberDiscordLinkController::class, 'update'])
        ->whereNumber('member')->name('roster.discord-link.update');
    Route::delete('/roster/{member}/discord-link', [MemberDiscordLinkController::class, 'destroy'])
        ->whereNumber('member')->name('roster.discord-link.destroy');

    // /run GRM.AddAlt(...) macro generator. Officer picks a target
    // member (datalist autocomplete on the page) and the macro links
    // the source row + target as alts of each other. GRM handles the
    // four "neither linked / one linked / both linked" combinations.
    Route::post('/roster/add-alt', [RosterAddAltMacroController::class, 'preview'])->name('roster.add-alt.preview');
    Route::post('/roster/add-alt/confirm', [RosterAddAltMacroController::class, 'confirm'])->name('roster.add-alt.confirm');

    // Warcraft Logs reports browser. /reports lists the recent reports;
    // /reports/{code} expands one report into fights + per-actor parses.
    Route::get('/reports', [ReportsController::class, 'index'])->name('reports.index');
    Route::get('/reports/{code}', [ReportsController::class, 'show'])
        ->where('code', '[A-Za-z0-9]+')->name('reports.show');

    // Character drilldown. Member name format is "Char-Realm" (the GRM
    // SavedVariables convention) so the param allows letters and a
    // single hyphen separator. Apostrophes are stripped by GRM, no
    // need to whitelist them.
    // Constraint is "anything except a slash" so realms like
    // Aggra(Português), Drak'thul, Sha'tar - anything with parens,
    // apostrophes, accents - route correctly. The controller's
    // firstOrFail() handles unknown names with a clean 404.
    Route::get('/character/{nameRealm}', [CharacterController::class, 'show'])
        ->where('nameRealm', '[^/]+')->name('character.show');

    // Per-member team override. Officers tick which teams a character
    // belongs to from the character page; the resolver keeps the rank-
    // derived team for everyone else. Empty selection reverts to rank.
    Route::post('/character/{nameRealm}/teams', [CharacterTeamOverrideController::class, 'update'])
        ->where('nameRealm', '[^/]+')->name('character.teams.update');

    Route::get('/events', [EventController::class, 'index'])->name('events.index');
    Route::get('/events/new', [EventController::class, 'create'])->name('events.create');
    Route::post('/events', [EventController::class, 'store'])->name('events.store');
    Route::post('/events/sync', [EventController::class, 'sync'])->name('events.sync');
    Route::get('/events/{event}', [EventController::class, 'show'])
        ->where('event', '[0-9]+')->name('events.show');
    Route::delete('/events/{event}', [EventController::class, 'destroy'])
        ->where('event', '[0-9]+')->name('events.destroy');

    // Team mapping admin: officers configure which in-game ranks and
    // Discord role IDs map to which raid team. Drives members.team and
    // users.team (set on next GRM ingest / next role check respectively).
    Route::get('/admin/teams', [TeamMappingController::class, 'index'])->name('admin.teams.index');
    Route::post('/admin/teams', [TeamMappingController::class, 'update'])->name('admin.teams.update');

    // Per-team raid schedule (days + time). Overrides config defaults so
    // raid leads can change Heroic from Tue/Thu to Mon/Wed without a
    // redeploy. Empty table is fine; pages fall back to config.
    Route::get('/admin/teams/schedule', [TeamScheduleController::class, 'index'])->name('admin.teams.schedule.index');
    Route::post('/admin/teams/schedule', [TeamScheduleController::class, 'update'])->name('admin.teams.schedule.update');
    Route::post('/admin/teams/schedule/{slug}/reset', [TeamScheduleController::class, 'reset'])
        ->where('slug', '[a-z_]+')->name('admin.teams.schedule.reset');

    // Discord role mentions: which @roles get pinged on each team's
    // Raid-Helper events. DB-backed so officers can rotate roles
    // without a redeploy. EventController reads via
    // DiscordRoleMentionResolver, so changes take effect on the next
    // event creation.
    Route::get('/admin/discord-roles', [DiscordRoleConfigController::class, 'index'])->name('admin.discord-roles.index');
    Route::post('/admin/discord-roles', [DiscordRoleConfigController::class, 'update'])->name('admin.discord-roles.update');

    // On-demand Raider.IO refresh. Same logic as the scheduled
    // raiderio:pull command; rate-limited per officer.
    Route::post('/admin/raiderio/sync', [RaiderioSyncController::class, 'store'])->name('admin.raiderio.sync');

    // On-demand wowaudit refresh. Same shape as raiderio.sync.
    Route::post('/admin/wowaudit/sync', [WowauditSyncController::class, 'store'])->name('admin.wowaudit.sync');

    // On-demand Warcraft Logs pull. Same shape as raiderio.sync.
    Route::post('/admin/wcl/sync', [WclSyncController::class, 'store'])->name('admin.wcl.sync');

    // On-demand Blizzard profile pull. Same shape as raiderio.sync.
    Route::post('/admin/blizzard/sync', [BlizzardSyncController::class, 'store'])->name('admin.blizzard.sync');

    // Dedicated sync dashboard: per-source status panels + GRM file
    // upload + on-demand sync triggers. Auto-refreshes while a sync
    // is in progress so officers can see results without reloading.
    Route::get('/admin/sync', [SyncDashboardController::class, 'index'])->name('admin.sync.index');
    Route::post('/admin/sync/grm', [SyncDashboardController::class, 'uploadGrm'])->name('admin.sync.grm.upload');

    // Widget order for the General dashboard, which only officers see.
    Route::post('/preferences/dashboard-layout', [PreferencesController::class, 'dashboardLayout'])->name('preferences.dashboard-layout');

    // Officer-managed Discord webhook table. Used by the digest sender
    // and any future webhook-based sender (event reminders etc).
    Route::get('/admin/webhooks', [DiscordWebhookController::class, 'index'])->name('admin.webhooks.index');
    Route::post('/admin/webhooks', [DiscordWebhookController::class, 'store'])->name('admin.webhooks.store');
    Route::put('/admin/webhooks/{webhook}', [DiscordWebhookController::class, 'update'])->name('admin.webhooks.update');
    Route::delete('/admin/webhooks/{webhook}', [DiscordWebhookController::class, 'destroy'])->name('admin.webhooks.destroy');
    Route::post('/admin/webhooks/{webhook}/test', [DiscordWebhookController::class, 'test'])->name('admin.webhooks.test');
    Route::post('/admin/webhooks/test-all', [DiscordWebhookController::class, 'testAll'])->name('admin.webhooks.test-all');

    // Shared "Regenesis Officers" Google Calendar push integration.
    // One officer authorises here; the dashboard creates a dedicated
    // calendar on their account and pushes raid events to it as they
    // are created/edited/deleted. Connect/disconnect/test live in this
    // page; the OAuth handshake itself is the auth.google-calendar.*
    // route group below.
    Route::get('/admin/google-calendar', [GoogleCalendarSettingsController::class, 'index'])->name('admin.google-calendar.index');
    Route::post('/admin/google-calendar/test', [GoogleCalendarSettingsController::class, 'test'])->name('admin.google-calendar.test');

    Route::get('/auth/google-calendar', [GoogleCalendarController::class, 'start'])->name('auth.google-calendar.start');
    Route::get('/auth/google-calendar/callback', [GoogleCalendarController::class, 'callback'])->name('auth.google-calendar.callback');
    Route::post('/auth/google-calendar/disconnect', [GoogleCalendarController::class, 'disconnect'])->name('auth.google-calendar.disconnect');
});

// .ics download for a single event. Signed via HMAC(ics_uid|ics_sequence)
// so editing an event invalidates old shared links. No auth required so
// the link can be DM'd around or embedded; signature is the only key.
// {event} constrained to numeric so /events/1.ics doesn't match the
// auth-protected /events/{event} route as event=`1.ics`.
Route::get('/events/{event}.ics', [IcsController::class, 'show'])
    ->where('event', '[0-9]+')->name('event.ics');

// Public world-events feed: Darkmoon Faire, holidays, Trading Post
// resets. No auth - it's just public WoW calendar info, anyone can
// subscribe. Cached for a day (these dates barely shift). Listed
// before the generic /calendar/{token}.ics route below so the
// literal "world" segment isn't captured as a token.
Route::get('/calendar/world.ics', [IcsController::class, 'worldFeed'])
    ->name('calendar.world');

// Combined Social feed: raid events plus computed world events. Same
// per-user token as the raid-only subscription above; users can pick
// whichever one they want in their calendar app. Tokens are bound to
// User rows; rotation is identical to the raid feed.
Route::get('/calendar/social/{token}.ics', [IcsController::class, 'socialSubscription'])
    ->name('calendar.social.subscription');

// Per-user webcal:// subscription feed. Token is a random column on the
// User row; rotate from the settings page if leaked. Returns rolling
// 90-day window (subset includes recent past so calendar clients can
// show 'what was today').
Route::get('/calendar/{token}.ics', [IcsController::class, 'subscription'])
    ->name('calendar.subscription');
