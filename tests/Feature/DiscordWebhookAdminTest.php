<?php

use App\Models\DiscordWebhook;
use App\Models\User;
use App\Services\Discord\WebhookRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'discord.guild_id' => '1247256415542841416',
        'discord.roles' => [
            'gm' => '1247279261434384415',
            'big6' => '1490762074584780951',
            'officer' => '1247278529163296789',
        ],
        'raidhelper.teams' => [
            'heroic' => ['label' => 'Heroic'],
            'mythic' => ['label' => 'Mythic'],
            'keynight' => ['label' => 'Keynight'],
        ],
    ]);
});

function webhookOfficer(): User
{
    return User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);
}

function makeWebhook(array $overrides = []): DiscordWebhook
{
    return DiscordWebhook::query()->create(array_replace([
        'label' => 'Officer chat',
        'url' => 'https://discord.com/api/webhooks/1234567890/abc-DEF_xyz',
        'purpose' => DiscordWebhook::PURPOSE_WEEKLY_DIGEST,
        'team_slug' => null,
        'enabled' => true,
    ], $overrides));
}

// --- Model ---------------------------------------------------------

it('encrypts the webhook URL at rest and decrypts it on read', function () {
    $w = makeWebhook(['url' => 'https://discord.com/api/webhooks/1/secret']);

    expect($w->fresh()->url)->toBe('https://discord.com/api/webhooks/1/secret');
    $raw = DB::table('discord_webhooks')->where('id', $w->id)->value('url');
    expect($raw)->not->toBe('https://discord.com/api/webhooks/1/secret');
    expect(Crypt::decryptString($raw))->toBe('https://discord.com/api/webhooks/1/secret');
});

// --- WebhookRouter -------------------------------------------------

it('router returns enabled webhooks matching a purpose', function () {
    makeWebhook(['label' => 'A', 'purpose' => DiscordWebhook::PURPOSE_WEEKLY_DIGEST]);
    makeWebhook(['label' => 'B', 'purpose' => DiscordWebhook::PURPOSE_WEEKLY_DIGEST, 'enabled' => false]);
    makeWebhook(['label' => 'C', 'purpose' => DiscordWebhook::PURPOSE_EVENT_ANNOUNCE]);

    $hits = (new WebhookRouter())->routeFor(DiscordWebhook::PURPOSE_WEEKLY_DIGEST);
    expect($hits->pluck('label')->all())->toBe(['A']);
});

it('router prefers team-scoped webhooks over guild-wide for the same purpose', function () {
    makeWebhook(['label' => 'guild',  'purpose' => DiscordWebhook::PURPOSE_EVENT_ANNOUNCE, 'team_slug' => null]);
    makeWebhook(['label' => 'heroic', 'purpose' => DiscordWebhook::PURPOSE_EVENT_ANNOUNCE, 'team_slug' => 'heroic']);

    $hits = (new WebhookRouter())->routeFor(DiscordWebhook::PURPOSE_EVENT_ANNOUNCE, 'heroic');
    expect($hits->pluck('label')->all())->toBe(['heroic']);
});

it('router falls back to guild-wide when no team-scoped webhook exists', function () {
    makeWebhook(['label' => 'guild', 'purpose' => DiscordWebhook::PURPOSE_EVENT_ANNOUNCE, 'team_slug' => null]);

    $hits = (new WebhookRouter())->routeFor(DiscordWebhook::PURPOSE_EVENT_ANNOUNCE, 'mythic');
    expect($hits->pluck('label')->all())->toBe(['guild']);
});

// --- Digest sender -------------------------------------------------

it('digest:weekly posts to every enabled weekly_digest webhook in the table', function () {
    Http::fake(['discord.com/*' => Http::response('', 204)]);
    config(['digest.discord_webhook_url' => '']); // no legacy fallback

    makeWebhook(['label' => 'Officer chat', 'url' => 'https://discord.com/api/webhooks/100/aaa']);
    makeWebhook(['label' => 'Backup',       'url' => 'https://discord.com/api/webhooks/200/bbb']);

    $this->artisan('digest:weekly')
        ->expectsOutputToContain('Posted weekly digest to 2 webhook(s)')
        ->assertExitCode(0);

    $urls = collect(Http::recorded())->map(fn ($pair) => $pair[0]->url())->all();
    expect($urls)->toContain('https://discord.com/api/webhooks/100/aaa');
    expect($urls)->toContain('https://discord.com/api/webhooks/200/bbb');
});

it('digest:weekly falls back to the legacy env var when no webhooks are configured', function () {
    Http::fake(['legacy.test/*' => Http::response('', 204)]);
    config(['digest.discord_webhook_url' => 'https://legacy.test/wh']);

    $this->artisan('digest:weekly')
        ->expectsOutputToContain('legacy DIGEST_DISCORD_WEBHOOK_URL')
        ->assertExitCode(0);

    Http::assertSent(fn ($req) => str_contains($req->url(), 'legacy.test'));
});

// --- Admin CRUD ----------------------------------------------------

it('non-officer is 403d from the webhook admin', function () {
    $u = User::factory()->create(['tier' => null, 'last_role_check_at' => now()]);
    $w = makeWebhook();

    $this->actingAs($u)->get('/admin/webhooks')->assertStatus(403);
    $this->actingAs($u)->post('/admin/webhooks', [])->assertStatus(403);
    $this->actingAs($u)->delete("/admin/webhooks/{$w->id}")->assertStatus(403);
});

it('admin index lists existing webhooks', function () {
    makeWebhook(['label' => 'Officer chat']);

    $this->actingAs(webhookOfficer())
        ->get('/admin/webhooks')
        ->assertOk()
        ->assertSee('Officer chat');
});

it('admin store creates a new webhook with valid input', function () {
    $this->actingAs(webhookOfficer())
        ->post('/admin/webhooks', [
            'label' => 'Heroic announce',
            'url' => 'https://discord.com/api/webhooks/999/secret_token',
            'purpose' => DiscordWebhook::PURPOSE_EVENT_ANNOUNCE,
            'team_slug' => 'heroic',
            'enabled' => 1,
        ])
        ->assertRedirect('/admin/webhooks')
        ->assertSessionHas('status');

    $w = DiscordWebhook::query()->where('label', 'Heroic announce')->first();
    expect($w)->not->toBeNull();
    expect($w->url)->toBe('https://discord.com/api/webhooks/999/secret_token');
    expect($w->team_slug)->toBe('heroic');
});

it('admin store rejects a non-Discord URL', function () {
    $this->actingAs(webhookOfficer())
        ->post('/admin/webhooks', [
            'label' => 'Bad',
            'url' => 'https://evil.example/wh',
            'purpose' => DiscordWebhook::PURPOSE_WEEKLY_DIGEST,
        ])
        ->assertSessionHasErrors('url');
});

it('admin update with empty url keeps the existing URL', function () {
    $w = makeWebhook(['url' => 'https://discord.com/api/webhooks/1/keepme']);

    $this->actingAs(webhookOfficer())
        ->put("/admin/webhooks/{$w->id}", [
            'label' => 'Renamed',
            'url' => '',  // intentionally blank
            'purpose' => $w->purpose,
            'team_slug' => null,
            'enabled' => 0,
        ])
        ->assertRedirect('/admin/webhooks');

    $w->refresh();
    expect($w->label)->toBe('Renamed');
    expect($w->url)->toBe('https://discord.com/api/webhooks/1/keepme');
    expect($w->enabled)->toBeFalse();
});

it('admin destroy removes the row', function () {
    $w = makeWebhook();

    $this->actingAs(webhookOfficer())
        ->delete("/admin/webhooks/{$w->id}")
        ->assertRedirect('/admin/webhooks');

    expect(DiscordWebhook::query()->find($w->id))->toBeNull();
});

it('admin test posts a ping to the webhook URL', function () {
    Http::fake(['discord.com/*' => Http::response('', 204)]);
    $w = makeWebhook(['label' => 'Officer chat', 'url' => 'https://discord.com/api/webhooks/100/aaa']);

    $this->actingAs(webhookOfficer())
        ->post("/admin/webhooks/{$w->id}/test")
        ->assertRedirect('/admin/webhooks')
        ->assertSessionHas('status');

    Http::assertSent(fn ($req) =>
        $req->url() === 'https://discord.com/api/webhooks/100/aaa'
        && str_contains($req['content'], 'Test ping')
    );
});
