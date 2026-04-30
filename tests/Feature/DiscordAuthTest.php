<?php

use App\Http\Middleware\OfficerOnly;
use App\Models\User;
use App\Services\Discord\RoleVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'discord.guild_id' => '1247256415542841416',
        'discord.roles' => [
            'gm' => '1247279261434384415',
            'big6' => '1490762074584780951',
            'officer' => '1247278529163296789',
            'raid_leader' => '1247653009014395031',
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

it('serves a stale officer immediately and defers the Discord re-check', function () {
    $user = makeUser(User::TIER_OFFICER);
    // Simulate stale cache: last check was longer ago than TTL.
    $user->forceFill(['last_role_check_at' => now()->subHour()])->save();

    // The deferred refresh runs in app()->terminating(); fake the HTTP
    // layer so it doesn't try to hit Discord for real.
    Http::fake();

    // The page render itself must NOT depend on Discord and must return
    // 200 even if no refresh token is present.
    $this->actingAs($user)->get('/dashboard')->assertOk();
});

it('does not lock out a still-valid officer when Discord token refresh blips', function () {
    $user = makeUser(User::TIER_OFFICER);
    $user->discord_refresh_token = 'placeholder-refresh-token';
    $user->save();

    // Simulate Discord returning a 503 on the token refresh endpoint.
    Http::fake([
        'discord.com/api/v10/oauth2/token' => Http::response('Service Unavailable', 503),
    ]);

    $tier = RoleVerifier::fromConfig()->tierFor($user, force: true);

    expect($tier)->toBe(User::TIER_OFFICER);

    // And the deny cache MUST NOT be set for the full TTL.
    $cached = Cache::get("discord.tier.user.{$user->id}");
    expect($cached)->not->toBe('');
});

it('locks out a user whose refresh token is genuinely rejected', function () {
    $user = makeUser(User::TIER_OFFICER);
    $user->discord_refresh_token = 'placeholder-refresh-token';
    $user->save();

    // Discord returns 400 invalid_grant when the refresh token is bad.
    Http::fake([
        'discord.com/api/v10/oauth2/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $tier = RoleVerifier::fromConfig()->tierFor($user, force: true);

    expect($tier)->toBeNull();
    expect(Cache::get("discord.tier.user.{$user->id}"))->toBe('');
});

it('isOfficerTier returns true for any of the four tiers', function () {
    expect((new User(['tier' => User::TIER_GM]))->isOfficerTier())->toBeTrue();
    expect((new User(['tier' => User::TIER_BIG6]))->isOfficerTier())->toBeTrue();
    expect((new User(['tier' => User::TIER_OFFICER]))->isOfficerTier())->toBeTrue();
    expect((new User(['tier' => User::TIER_RAID_LEADER]))->isOfficerTier())->toBeTrue();
    expect((new User(['tier' => null]))->isOfficerTier())->toBeFalse();
    expect((new User(['tier' => 'guildie']))->isOfficerTier())->toBeFalse();
});

it('isAtLeast respects the tier ladder', function () {
    $gm = new User(['tier' => User::TIER_GM]);
    $big6 = new User(['tier' => User::TIER_BIG6]);
    $officer = new User(['tier' => User::TIER_OFFICER]);
    $raidLeader = new User(['tier' => User::TIER_RAID_LEADER]);

    expect($gm->isAtLeast(User::TIER_RAID_LEADER))->toBeTrue();
    expect($gm->isAtLeast(User::TIER_OFFICER))->toBeTrue();
    expect($gm->isAtLeast(User::TIER_BIG6))->toBeTrue();
    expect($gm->isAtLeast(User::TIER_GM))->toBeTrue();

    expect($big6->isAtLeast(User::TIER_RAID_LEADER))->toBeTrue();
    expect($big6->isAtLeast(User::TIER_OFFICER))->toBeTrue();
    expect($big6->isAtLeast(User::TIER_BIG6))->toBeTrue();
    expect($big6->isAtLeast(User::TIER_GM))->toBeFalse();

    expect($officer->isAtLeast(User::TIER_RAID_LEADER))->toBeTrue();
    expect($officer->isAtLeast(User::TIER_OFFICER))->toBeTrue();
    expect($officer->isAtLeast(User::TIER_BIG6))->toBeFalse();
    expect($officer->isAtLeast(User::TIER_GM))->toBeFalse();

    expect($raidLeader->isAtLeast(User::TIER_RAID_LEADER))->toBeTrue();
    expect($raidLeader->isAtLeast(User::TIER_OFFICER))->toBeFalse();
    expect($raidLeader->isAtLeast(User::TIER_BIG6))->toBeFalse();
    expect($raidLeader->isAtLeast(User::TIER_GM))->toBeFalse();
});

it('RoleVerifier picks the highest matching tier', function () {
    $verifier = new RoleVerifier(
        guildId: 'guild',
        tierRoleIds: ['gm' => 'GM', 'big6' => 'BIG', 'officer' => 'OFF', 'raid_leader' => 'RL'],
    );

    expect($verifier->tierFromRoles(['random', 'RL']))->toBe('raid_leader');
    expect($verifier->tierFromRoles(['random', 'OFF']))->toBe('officer');
    expect($verifier->tierFromRoles(['BIG', 'OFF', 'RL']))->toBe('big6');
    expect($verifier->tierFromRoles(['GM', 'BIG', 'OFF', 'RL']))->toBe('gm');
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
