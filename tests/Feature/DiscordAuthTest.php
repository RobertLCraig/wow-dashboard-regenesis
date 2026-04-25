<?php

use App\Http\Middleware\OfficerOnly;
use App\Models\User;
use App\Services\Discord\RoleVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'discord.guild_id' => '1247256415542841416',
        'discord.roles' => [
            'gm' => '1247279261434384415',
            'big6' => '1490762074584780951',
            'officer' => '1247278529163296789',
        ],
        'discord.role_cache_ttl_minutes' => 5,
    ]);
    Cache::flush();
});

function makeUser(?string $tier = null): User
{
    return User::factory()->create([
        'discord_id' => '900000000000000000',
        'tier' => $tier,
        'last_role_check_at' => now(),
    ]);
}

it('redirects unauthenticated requests to the OAuth start', function () {
    $this->get('/dashboard')->assertRedirect('/auth/discord');
});

it('403s an authenticated user with no tier', function () {
    $user = makeUser(null);

    $this->actingAs($user)->get('/dashboard')->assertStatus(403);
});

it('lets a fresh-tier user through', function () {
    $user = makeUser(User::TIER_OFFICER);

    $this->actingAs($user)->get('/dashboard')->assertOk();
});

it('rerolls Discord when the cached tier is stale', function () {
    $user = makeUser(User::TIER_OFFICER);
    // Simulate stale cache: last check was longer ago than TTL.
    $user->forceFill(['last_role_check_at' => now()->subHour()])->save();

    // Without a refresh token Discord can't be queried; that path
    // returns no tier and the middleware 403s.
    $this->actingAs($user)->get('/dashboard')->assertStatus(403);
});

it('isOfficerTier returns true for any of the three tiers', function () {
    expect((new User(['tier' => User::TIER_GM]))->isOfficerTier())->toBeTrue();
    expect((new User(['tier' => User::TIER_BIG6]))->isOfficerTier())->toBeTrue();
    expect((new User(['tier' => User::TIER_OFFICER]))->isOfficerTier())->toBeTrue();
    expect((new User(['tier' => null]))->isOfficerTier())->toBeFalse();
    expect((new User(['tier' => 'guildie']))->isOfficerTier())->toBeFalse();
});

it('isAtLeast respects the tier ladder', function () {
    $gm = new User(['tier' => User::TIER_GM]);
    $big6 = new User(['tier' => User::TIER_BIG6]);
    $officer = new User(['tier' => User::TIER_OFFICER]);

    expect($gm->isAtLeast(User::TIER_OFFICER))->toBeTrue();
    expect($gm->isAtLeast(User::TIER_BIG6))->toBeTrue();
    expect($gm->isAtLeast(User::TIER_GM))->toBeTrue();

    expect($big6->isAtLeast(User::TIER_OFFICER))->toBeTrue();
    expect($big6->isAtLeast(User::TIER_BIG6))->toBeTrue();
    expect($big6->isAtLeast(User::TIER_GM))->toBeFalse();

    expect($officer->isAtLeast(User::TIER_OFFICER))->toBeTrue();
    expect($officer->isAtLeast(User::TIER_BIG6))->toBeFalse();
    expect($officer->isAtLeast(User::TIER_GM))->toBeFalse();
});

it('RoleVerifier picks the highest matching tier', function () {
    $verifier = new RoleVerifier(
        guildId: 'guild',
        tierRoleIds: ['gm' => 'GM', 'big6' => 'BIG', 'officer' => 'OFF'],
    );

    expect($verifier->tierFromRoles(['random', 'OFF']))->toBe('officer');
    expect($verifier->tierFromRoles(['BIG', 'OFF']))->toBe('big6');
    expect($verifier->tierFromRoles(['GM', 'BIG', 'OFF']))->toBe('gm');
    expect($verifier->tierFromRoles(['unrelated']))->toBeNull();
    expect($verifier->tierFromRoles([]))->toBeNull();
});

it('officer-tier gates allow officer-tier users', function () {
    $user = makeUser(User::TIER_OFFICER);

    expect($user->can('events.create'))->toBeTrue();
    expect($user->can('roster.view'))->toBeTrue();
    expect($user->can('bans.manage'))->toBeTrue();
});

it('officer-tier gates deny users with no tier', function () {
    $user = makeUser(null);

    expect($user->can('events.create'))->toBeFalse();
    expect($user->can('roster.view'))->toBeFalse();
});
