<?php

use App\Http\Middleware\RequireTier;
use App\Models\User;
use App\Services\Discord\RoleVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'grm.guild_key' => 'Regenesis-Silvermoon',
        'discord.guild_id' => 'guild',
        'discord.roles' => [
            'gm' => 'GM',
            'big6' => 'BIG',
            'officer' => 'OFF',
            'raid_leader' => 'RL',
            'member' => 'MEM',
        ],
    ]);
    // Nothing here should reach Discord; a stale re-check would.
    Http::fake();
});

function tierUser(?string $tier): User
{
    return User::factory()->create(['tier' => $tier, 'last_role_check_at' => now()]);
}

/**
 * Every parameter-free GET page behind the officer gate. Read off the
 * router rather than hand-listed, so a route added later is walked too.
 *
 * @return array<int,string>
 */
function officerGetUris(): array
{
    $uris = [];
    foreach (Route::getRoutes() as $route) {
        $middleware = $route->gatherMiddleware();
        $officerGated = in_array(RequireTier::class, $middleware, true);
        if ($officerGated && in_array('GET', $route->methods(), true) && ! str_contains($route->uri(), '{')) {
            $uris[] = '/'.ltrim($route->uri(), '/');
        }
    }

    return $uris;
}

// ── #1 an ordinary member reaches the guild-wide pages ────────────────

it('lets a member reach Social', function () {
    $this->actingAs(tierUser(User::TIER_MEMBER))->get('/dashboard/social')->assertOk();
});

it('lets a member reach the Roster and its CSV', function () {
    $this->actingAs(tierUser(User::TIER_MEMBER))->get('/roster')->assertOk();
    $this->actingAs(tierUser(User::TIER_MEMBER))->get('/roster.csv')->assertOk();
});

it('shows a member the Social and Roster links and no admin links', function () {
    $resp = $this->actingAs(tierUser(User::TIER_MEMBER))->get('/dashboard/social');

    $resp->assertOk()
        ->assertSee('Social')
        ->assertSee('Roster')
        ->assertDontSee('Team mapping')
        ->assertDontSee('Webhooks');
});

it('lands a member on Social after sign-in, and an officer on the General dashboard', function () {
    expect(tierUser(User::TIER_MEMBER)->homeRoute())->toBe('dashboard.social');
    expect(tierUser(User::TIER_OFFICER)->homeRoute())->toBe('dashboard');
    expect(tierUser(User::TIER_GM)->homeRoute())->toBe('dashboard');
});

// ── #2 a member is refused every officer and admin page ───────────────

it('403s a member on every officer page', function () {
    $member = tierUser(User::TIER_MEMBER);

    foreach (officerGetUris() as $uri) {
        expect($this->actingAs($member)->get($uri)->status())
            ->toBe(403, "member should be refused {$uri}");
    }
});

it('403s a member on the roster write endpoints', function () {
    $member = tierUser(User::TIER_MEMBER);

    $this->actingAs($member)->post('/roster/kick-macro', [])->assertForbidden();
    $this->actingAs($member)->post('/roster/rank-macro', [])->assertForbidden();
    $this->actingAs($member)->post('/events', [])->assertForbidden();
    $this->actingAs($member)->post('/admin/teams', [])->assertForbidden();
});

// ── #3 no guild role at all is refused everything ─────────────────────

it('403s a user with no tier on the member pages too', function () {
    $nobody = tierUser(null);

    $this->actingAs($nobody)->get('/dashboard/social')->assertForbidden();
    $this->actingAs($nobody)->get('/roster')->assertForbidden();
    $this->actingAs($nobody)->get('/dashboard')->assertForbidden();
});

it('redirects a signed-out visitor to the OAuth start, member pages included', function () {
    $this->get('/dashboard/social')->assertRedirect('/auth/discord');
    $this->get('/roster')->assertRedirect('/auth/discord');
});

// ── #4 the officer experience is unchanged ────────────────────────────

it('walks an officer through every page that was officer-only', function () {
    $officer = tierUser(User::TIER_OFFICER);

    $uris = officerGetUris();
    expect($uris)->not->toBeEmpty();

    // 200 renders, 302 is the Google OAuth hand-off. Anything else -
    // a 403 or a page that blew up - fails the walk.
    foreach ($uris as $uri) {
        expect($this->actingAs($officer)->get($uri)->status())
            ->toBeIn([200, 302], "officer should still reach {$uri}");
    }
});

it('lets an officer reach the pages that moved to the member gate', function () {
    $officer = tierUser(User::TIER_OFFICER);

    $this->actingAs($officer)->get('/dashboard/social')->assertOk();
    $this->actingAs($officer)->get('/roster')->assertOk();
    $this->actingAs($officer)->get('/roster.csv')->assertOk();
});

// ── the tier itself ───────────────────────────────────────────────────

it('reads the member role off Discord, and an officer role still wins', function () {
    $verifier = new RoleVerifier(
        guildId: 'guild',
        tierRoleIds: ['gm' => 'GM', 'big6' => 'BIG', 'officer' => 'OFF', 'raid_leader' => 'RL', 'member' => 'MEM'],
    );

    expect($verifier->tierFromRoles(['MEM']))->toBe(User::TIER_MEMBER);
    expect($verifier->tierFromRoles(['MEM', 'OFF']))->toBe(User::TIER_OFFICER);
    expect($verifier->tierFromRoles(['MEM', 'GM']))->toBe(User::TIER_GM);
    expect($verifier->tierFromRoles(['some-other-role']))->toBeNull();
});

it('ranks member below every officer tier and above no tier', function () {
    $member = new User(['tier' => User::TIER_MEMBER]);

    expect($member->isAtLeast(User::TIER_MEMBER))->toBeTrue();
    expect($member->isAtLeast(User::TIER_RAID_LEADER))->toBeFalse();
    expect($member->isAtLeast(User::TIER_OFFICER))->toBeFalse();
    expect($member->isOfficerTier())->toBeFalse();

    expect((new User(['tier' => User::TIER_RAID_LEADER]))->isAtLeast(User::TIER_MEMBER))->toBeTrue();
    expect((new User(['tier' => User::TIER_GM]))->isAtLeast(User::TIER_MEMBER))->toBeTrue();
    expect((new User(['tier' => null]))->isAtLeast(User::TIER_MEMBER))->toBeFalse();
    expect((new User(['tier' => 'pending']))->isAtLeast(User::TIER_MEMBER))->toBeFalse();
});
