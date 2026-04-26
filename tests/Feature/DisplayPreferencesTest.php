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

it('persists a switch to high-clarity mode', function () {
    $u = makePrefsOfficer();

    $this->actingAs($u)
        ->post(route('preferences.display'), ['display_mode' => User::DISPLAY_HIGH_CLARITY])
        ->assertRedirect();

    expect($u->fresh()->display_mode)->toBe(User::DISPLAY_HIGH_CLARITY);
});

it('persists a switch back to standard mode', function () {
    $u = makePrefsOfficer();
    $u->forceFill(['display_mode' => User::DISPLAY_HIGH_CLARITY])->save();

    $this->actingAs($u)
        ->post(route('preferences.display'), ['display_mode' => User::DISPLAY_STANDARD])
        ->assertRedirect();

    expect($u->fresh()->display_mode)->toBe(User::DISPLAY_STANDARD);
});

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

it('renders the body with mode-standard for a default user', function () {
    $u = makePrefsOfficer();

    $response = $this->actingAs($u)->get(route('dashboard'));

    $response->assertOk();
    expect($response->getContent())->toContain('mode-standard');
});

it('renders the body with mode-high-clarity once the user opts in', function () {
    $u = makePrefsOfficer();
    $u->forceFill(['display_mode' => User::DISPLAY_HIGH_CLARITY])->save();

    $response = $this->actingAs($u)->get(route('dashboard'));

    $response->assertOk();
    expect($response->getContent())->toContain('mode-high-clarity');
});
