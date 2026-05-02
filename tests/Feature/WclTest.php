<?php

use App\Jobs\SyncWclReportsJob;
use App\Models\User;
use App\Models\WclReport;
use App\Services\Sync\SyncStatus;
use App\Services\Wcl\WclClient;
use App\Services\Wcl\WclReportImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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
        ],
        'grm.guild_key' => 'Regenesis-Silvermoon',
        'wcl.client_id' => 'test-client-id',
        'wcl.client_secret' => 'test-client-secret',
        'wcl.token_url' => 'https://wcl.test/oauth/token',
        'wcl.graphql_url' => 'https://wcl.test/api/v2/client',
        'wcl.guild_name' => 'Regenesis',
        'wcl.guild_server_slug' => 'silvermoon',
        'wcl.guild_server_region' => 'EU',
        'wcl.timeout' => 5,
        'wcl.reports_per_pull' => 25,
    ]);
    Cache::flush();
});

function wclOfficer(): User
{
    return User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);
}

function fakeReportsResponse(?array $reports = null): array
{
    $reports ??= [
        ['code' => 'aaaaaaaaaa', 'title' => 'Tuesday Heroic', 'startTime' => 1750000000000, 'endTime' => 1750010000000, 'zone' => ['id' => 50, 'name' => 'Manaforge Omega'], 'owner' => ['name' => 'OfficerOne']],
        ['code' => 'bbbbbbbbbb', 'title' => 'Wednesday Mythic', 'startTime' => 1750100000000, 'endTime' => 1750110000000, 'zone' => ['id' => 50, 'name' => 'Manaforge Omega'], 'owner' => ['name' => 'OfficerTwo']],
    ];
    return ['data' => ['reportData' => ['reports' => ['data' => $reports]]]];
}

// --- WclClient ----------------------------------------------------

it('client fetches and caches the access token, then reuses it', function () {
    Http::fake([
        'wcl.test/oauth/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600, 'token_type' => 'Bearer'], 200),
        'wcl.test/api/v2/client' => Http::response(fakeReportsResponse([]), 200),
    ]);

    $client = WclClient::fromConfig();
    expect($client->accessToken())->toBe('tok-1');
    expect($client->accessToken())->toBe('tok-1'); // second call uses cache
    Http::assertSentCount(1);
});

it('client throws a clean error when credentials are missing', function () {
    config(['wcl.client_id' => '', 'wcl.client_secret' => '']);
    $client = WclClient::fromConfig();
    expect($client->isConfigured())->toBeFalse();
    expect(fn () => $client->accessToken())->toThrow(\RuntimeException::class, 'not configured');
});

it('client throws when the token endpoint 4xxs', function () {
    Http::fake(['wcl.test/oauth/token' => Http::response('forbidden', 403)]);
    expect(fn () => WclClient::fromConfig()->accessToken())
        ->toThrow(\RuntimeException::class, 'WCL token endpoint returned 403');
});

it('client posts the GraphQL query with the cached token as bearer', function () {
    Http::fake([
        'wcl.test/oauth/token' => Http::response(['access_token' => 'tok-X', 'expires_in' => 3600], 200),
        'wcl.test/api/v2/client' => Http::response(fakeReportsResponse(), 200),
    ]);

    WclClient::fromConfig()->query('query Foo { x }', ['x' => 1]);

    Http::assertSent(fn ($req) =>
        $req->url() === 'https://wcl.test/api/v2/client'
        && $req->hasHeader('Authorization', 'Bearer tok-X')
        && $req['query'] === 'query Foo { x }'
        && $req['variables']->x === 1
    );
});

// --- WclReportImporter --------------------------------------------

it('importer upserts new reports and counts inserts vs updates', function () {
    Http::fake([
        'wcl.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
        'wcl.test/api/v2/client' => Http::response(fakeReportsResponse(), 200),
    ]);

    $r = WclReportImporter::fromConfig()->pull();

    expect($r['fetched'])->toBe(2);
    expect($r['inserted'])->toBe(2);
    expect($r['updated'])->toBe(0);
    expect(WclReport::query()->count())->toBe(2);

    $a = WclReport::query()->where('code', 'aaaaaaaaaa')->first();
    expect($a->title)->toBe('Tuesday Heroic');
    expect($a->zone_id)->toBe(50);
    expect($a->zone_name)->toBe('Manaforge Omega');
    expect($a->owner_name)->toBe('OfficerOne');
    expect($a->start_time->timestamp)->toBe(1750000000);  // millis -> seconds
});

it('importer re-running on the same payload only bumps captured_at and counts as updates', function () {
    Http::fake([
        'wcl.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
        'wcl.test/api/v2/client' => Http::response(fakeReportsResponse(), 200),
    ]);
    WclReportImporter::fromConfig()->pull();
    $r = WclReportImporter::fromConfig()->pull();

    expect($r['inserted'])->toBe(0);
    expect($r['updated'])->toBe(2);
    expect(WclReport::query()->count())->toBe(2);
});

it('importer surfaces a GraphQL errors envelope as a runtime exception', function () {
    Http::fake([
        'wcl.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
        'wcl.test/api/v2/client' => Http::response(['errors' => [['message' => 'guild not found']]], 200),
    ]);

    expect(fn () => WclReportImporter::fromConfig()->pull())
        ->toThrow(\RuntimeException::class, 'guild not found');
});

it('importer retries once with a fresh token on 401', function () {
    Cache::put('wcl.access_token', 'stale-tok', 3600);
    $authCalls = 0;
    Http::fake([
        'wcl.test/oauth/token' => function () use (&$authCalls) {
            $authCalls++;
            return Http::response(['access_token' => 'fresh-tok', 'expires_in' => 3600], 200);
        },
        'wcl.test/api/v2/client' => Http::sequence()
            ->push('unauthorized', 401)
            ->push(fakeReportsResponse(), 200),
    ]);

    $r = WclReportImporter::fromConfig()->pull();

    expect($r['inserted'])->toBe(2);
    expect($authCalls)->toBe(1); // got a fresh token after the 401
});

// --- artisan + sync controller ------------------------------------

it('wcl:pull command short-circuits when credentials are missing', function () {
    config(['wcl.client_id' => '', 'wcl.client_secret' => '']);
    $this->artisan('wcl:pull')
        ->expectsOutputToContain('not set; skipping')
        ->assertExitCode(0);
});

it('wcl:pull command runs the reports importer end-to-end (fights skipped via flag)', function () {
    Http::fake([
        'wcl.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
        'wcl.test/api/v2/client' => Http::response(fakeReportsResponse(), 200),
    ]);

    $this->artisan('wcl:pull', ['--no-fights' => true])
        ->expectsOutputToContain('Reports: fetched 2, 2 new')
        ->assertExitCode(0);
});

it('wcl:pull also backfills fights for newly inserted reports by default', function () {
    Http::fake([
        'wcl.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
        // First call: report list. Subsequent: empty deep-report nodes
        // (we just want the command to wire up the second importer).
        'wcl.test/api/v2/client' => Http::sequence()
            ->push(fakeReportsResponse(), 200)
            ->whenEmpty(Http::response(['data' => ['reportData' => ['report' => [
                'code' => 'x', 'title' => 'x', 'fights' => [],
                'damage' => '{"data":{"entries":[]}}',
                'healing' => '{"data":{"entries":[]}}',
            ]]]], 200)),
    ]);

    $this->artisan('wcl:pull')
        ->expectsOutputToContain('Reports: fetched 2, 2 new')
        ->expectsOutputToContain('Fights: processed 2 reports')
        ->assertExitCode(0);
});

it('officer can trigger an on-demand WCL sync via the controller', function () {
    Bus::fake();
    $this->actingAs(wclOfficer())
        ->post('/admin/wcl/sync')
        ->assertRedirect('/admin/sync');

    Bus::assertDispatchedAfterResponse(SyncWclReportsJob::class);

    $state = SyncStatus::get(SyncStatus::SOURCE_WCL);
    expect($state['status'])->toBe('queued');
});

it('WCL sync controller refuses cleanly when credentials are missing', function () {
    config(['wcl.client_id' => '']);
    $this->actingAs(wclOfficer())
        ->post('/admin/wcl/sync')
        ->assertRedirect('/admin/sync')
        ->assertSessionHasErrors('wcl');
});

it('WCL sync short-circuits when a recently captured report exists', function () {
    Bus::fake();
    WclReport::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'code' => 'cccccccccc',
        'title' => 'Recent',
        'start_time' => now()->subHour(),
        'captured_at' => now()->subMinutes(5),
    ]);

    $this->actingAs(wclOfficer())
        ->post('/admin/wcl/sync')
        ->assertRedirect('/admin/sync')
        ->assertSessionHas('status', fn ($s) => str_contains($s, 'already fresh'));

    Bus::assertNotDispatchedAfterResponse(SyncWclReportsJob::class);
});

it('non-officer is 403d from the WCL sync route', function () {
    $u = User::factory()->create(['tier' => null, 'last_role_check_at' => now()]);
    $this->actingAs($u)->post('/admin/wcl/sync')->assertStatus(403);
});

it('sync dashboard shows the WCL panel with last-sync summary when a report exists', function () {
    WclReport::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'code' => 'dddddddddd',
        'title' => 'Tuesday Heroic',
        'start_time' => now()->subHour(),
        'captured_at' => now()->subMinutes(15),
    ]);

    $this->actingAs(wclOfficer())
        ->get('/admin/sync')
        ->assertOk()
        ->assertSee('Tuesday Heroic');
});
