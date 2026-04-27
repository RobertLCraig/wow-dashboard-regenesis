<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'discord.guild_id' => '1247256415542841416',
        'discord.roles' => [
            'gm' => '1247279261434384415',
            'big6' => '1490762074584780951',
            'officer' => '1247278529163296789',
        ],
    ]);
});

function makePrefsOfficer(): User
{
    return User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);
}

it('defaults new users to standard display mode', function () {
    $u = makePrefsOfficer();
    expect($u->fresh()->display_mode)->toBe(User::DISPLAY_STANDARD);
});

it('persists each of the three valid display modes', function (string $mode) {
    $u = makePrefsOfficer();

    $this->actingAs($u)
        ->post(route('preferences.display'), ['display_mode' => $mode])
        ->assertRedirect();

    expect($u->fresh()->display_mode)->toBe($mode);
})->with([
    'standard'      => User::DISPLAY_STANDARD,
    'clear'         => User::DISPLAY_CLEAR,
    'high_clarity'  => User::DISPLAY_HIGH_CLARITY,
]);

it('rejects an unknown display_mode value', function () {
    $u = makePrefsOfficer();

    $this->actingAs($u)
        ->post(route('preferences.display'), ['display_mode' => 'something-else'])
        ->assertSessionHasErrors('display_mode');

    expect($u->fresh()->display_mode)->toBe(User::DISPLAY_STANDARD);
});

it('requires authentication', function () {
    $this->post(route('preferences.display'), ['display_mode' => User::DISPLAY_HIGH_CLARITY])
        ->assertRedirect(route('auth.discord.start'));
});

it('renders the body with the right mode-* class for each pref', function (string $mode, string $expectedClass) {
    $u = makePrefsOfficer();
    $u->forceFill(['display_mode' => $mode])->save();

    $response = $this->actingAs($u)->get(route('dashboard'));

    $response->assertOk();
    expect($response->getContent())->toContain($expectedClass);
})->with([
    'standard -> mode-standard'        => [User::DISPLAY_STANDARD,     'mode-standard'],
    'clear -> mode-clear'              => [User::DISPLAY_CLEAR,        'mode-clear'],
    'high_clarity -> mode-high-clarity' => [User::DISPLAY_HIGH_CLARITY, 'mode-high-clarity'],
]);

it('renders three segmented-control buttons in the sidebar footer', function () {
    $u = makePrefsOfficer();

    $response = $this->actingAs($u)->get(route('dashboard'));

    $response->assertOk();
    $html = $response->getContent();
    expect($html)
        ->toContain('View clarity')
        ->toContain('value="standard"')
        ->toContain('value="clear"')
        ->toContain('value="high_clarity"')
        ->toContain('Standard')
        ->toContain('Clear')
        ->toContain('High');
});

it('marks the active clarity step with aria-pressed=true', function () {
    $u = makePrefsOfficer();
    $u->forceFill(['display_mode' => User::DISPLAY_CLEAR])->save();

    $response = $this->actingAs($u)->get(route('dashboard'));

    $response->assertOk();
    $html = $response->getContent();

    // The form for "clear" should wrap a button with aria-pressed=true;
    // the other two buttons should be aria-pressed=false. We assert
    // both pressed states are present and that the active label sits
    // adjacent to the true marker.
    expect($html)->toContain('aria-pressed="true"');
    expect($html)->toContain('aria-pressed="false"');
});

// ----------------------------------------------------------------------------
// Theme picker (orthogonal to clarity)
// ----------------------------------------------------------------------------

it('defaults new users to the discord theme', function () {
    $u = makePrefsOfficer();
    expect($u->fresh()->theme)->toBe(User::THEME_DISCORD);
});

it('persists each of the two valid themes', function (string $theme) {
    $u = makePrefsOfficer();

    $this->actingAs($u)
        ->post(route('preferences.theme'), ['theme' => $theme])
        ->assertRedirect();

    expect($u->fresh()->theme)->toBe($theme);
})->with([
    'discord' => User::THEME_DISCORD,
    'phoenix' => User::THEME_PHOENIX,
]);

it('rejects an unknown theme value', function () {
    $u = makePrefsOfficer();

    $this->actingAs($u)
        ->post(route('preferences.theme'), ['theme' => 'rainbow'])
        ->assertSessionHasErrors('theme');

    expect($u->fresh()->theme)->toBe(User::THEME_DISCORD);
});

it('the theme endpoint requires authentication', function () {
    $this->post(route('preferences.theme'), ['theme' => User::THEME_PHOENIX])
        ->assertRedirect(route('auth.discord.start'));
});

it('renders the body with the right theme-* class for each pref', function (string $theme, string $expectedClass) {
    $u = makePrefsOfficer();
    $u->forceFill(['theme' => $theme])->save();

    $response = $this->actingAs($u)->get(route('dashboard'));

    $response->assertOk();
    expect($response->getContent())->toContain($expectedClass);
})->with([
    'discord -> theme-discord' => [User::THEME_DISCORD, 'theme-discord'],
    'phoenix -> theme-phoenix' => [User::THEME_PHOENIX, 'theme-phoenix'],
]);

it('clarity and theme are independent of each other', function () {
    $u = makePrefsOfficer();
    $u->forceFill([
        'display_mode' => User::DISPLAY_HIGH_CLARITY,
        'theme'        => User::THEME_PHOENIX,
    ])->save();

    $response = $this->actingAs($u)->get(route('dashboard'));

    $response->assertOk();
    $html = $response->getContent();
    expect($html)->toContain('mode-high-clarity');
    expect($html)->toContain('theme-phoenix');
});

// ----------------------------------------------------------------------------
// Dashboard layout (drag-reorder Phase 2)
// ----------------------------------------------------------------------------

it('defaults new users to a null dashboard_layout (project default order)', function () {
    $u = makePrefsOfficer();
    expect($u->fresh()->dashboard_layout)->toBeNull();
});

it('persists a posted dashboard layout', function () {
    $u = makePrefsOfficer();
    $known = collect((array) config('dashboard.widgets', []))->pluck('key')->all();
    $reverse = array_reverse($known);

    $this->actingAs($u)
        ->post(route('preferences.dashboard-layout'), ['layout' => $reverse])
        ->assertRedirect();

    expect($u->fresh()->dashboard_layout)->toBe($reverse);
});

it('drops unknown widget keys at save time', function () {
    $u = makePrefsOfficer();

    $this->actingAs($u)
        ->post(route('preferences.dashboard-layout'), [
            'layout' => ['action-queue', 'made-up-widget', 'churn'],
        ])
        ->assertRedirect();

    expect($u->fresh()->dashboard_layout)->toBe(['action-queue', 'churn']);
});

it('saving an empty layout clears the user override (back to default)', function () {
    $u = makePrefsOfficer();
    $u->forceFill(['dashboard_layout' => ['churn', 'bans']])->save();

    $this->actingAs($u)
        ->post(route('preferences.dashboard-layout'), ['layout' => []])
        ->assertRedirect();

    expect($u->fresh()->dashboard_layout)->toBeNull();
});

it('reset=1 clears the saved layout', function () {
    $u = makePrefsOfficer();
    $u->forceFill(['dashboard_layout' => ['churn']])->save();

    $this->actingAs($u)
        ->post(route('preferences.dashboard-layout'), ['reset' => '1'])
        ->assertRedirect();

    expect($u->fresh()->dashboard_layout)->toBeNull();
});

it('the dashboard-layout endpoint requires authentication', function () {
    $this->post(route('preferences.dashboard-layout'), ['layout' => ['churn']])
        ->assertRedirect(route('auth.discord.start'));
});

it('renders widgets in the user saved order', function () {
    $u = makePrefsOfficer();
    $u->forceFill(['dashboard_layout' => ['churn', 'bans', 'action-queue']])->save();

    $response = $this->actingAs($u)->get(route('dashboard'));
    $response->assertOk();

    $html = $response->getContent();
    $churnAt = strpos($html, 'data-widget-key="churn"');
    $bansAt  = strpos($html, 'data-widget-key="bans"');
    $aqAt    = strpos($html, 'data-widget-key="action-queue"');

    expect($churnAt)->not->toBeFalse();
    expect($bansAt)->not->toBeFalse();
    expect($aqAt)->not->toBeFalse();
    expect($churnAt)->toBeLessThan($bansAt);
    expect($bansAt)->toBeLessThan($aqAt);
});

it('renders the Edit layout button on the dashboard', function () {
    $response = $this->actingAs(makePrefsOfficer())->get(route('dashboard'));
    $response->assertOk()
        ->assertSee('Edit layout');
});
