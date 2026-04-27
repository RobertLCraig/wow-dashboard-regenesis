<?php

use App\Services\Blizzard\BlizzardClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'blizzard.region' => 'eu',
        'blizzard.client_id' => 'test-client-id',
        'blizzard.client_secret' => 'test-client-secret',
        'blizzard.api_base_url' => 'https://eu.api.blizzard.test',
        'blizzard.oauth_token_url' => 'https://oauth.battle.test/token',
        'blizzard.namespace' => 'profile-eu',
        'blizzard.locale' => 'en_GB',
        'blizzard.timeout' => 5,
        'blizzard.token_cache_ttl' => 60,
    ]);
    Cache::flush();
});

it('derives the api base and namespace from region when overrides are blank', function () {
    config([
        'blizzard.region' => 'us',
        'blizzard.api_base_url' => '',
        'blizzard.namespace' => '',
    ]);

    Http::fake([
        'oauth.battle.test/token' => Http::response(['access_token' => 'tok', 'expires_in' => 86399], 200),
        'us.api.blizzard.com/profile/wow/character/*' => Http::response(['equipped_item_level' => 282], 200),
    ]);

    BlizzardClient::fromConfig()->profile('stormrage', 'Sheday');

    Http::assertSent(function ($req) {
        return str_contains($req->url(), 'us.api.blizzard.com/profile/wow/character/stormrage/sheday')
            && $req->hasHeader('Battlenet-Namespace', 'profile-us');
    });
});

it('exchanges client credentials for an access token on first use', function () {
    Http::fake([
        'oauth.battle.test/token' => Http::response(['access_token' => 'shiny-token', 'expires_in' => 86399], 200),
        'eu.api.blizzard.test/*' => Http::response(['equipped_item_level' => 282], 200),
    ]);

    BlizzardClient::fromConfig()->profile('silvermoon', 'Sheday');

    Http::assertSent(fn ($req) =>
        $req->url() === 'https://oauth.battle.test/token'
        && $req->method() === 'POST'
        && $req->body() === 'grant_type=client_credentials'
        && $req->hasHeader('Authorization', 'Basic ' . base64_encode('test-client-id:test-client-secret'))
    );
    Http::assertSent(fn ($req) =>
        str_contains($req->url(), 'eu.api.blizzard.test/profile/wow/character/silvermoon/sheday')
        && $req->hasHeader('Authorization', 'Bearer shiny-token')
        && $req->hasHeader('Battlenet-Namespace', 'profile-eu')
    );
});

it('caches the access token across calls', function () {
    Http::fake([
        'oauth.battle.test/token' => Http::response(['access_token' => 'cached-tok', 'expires_in' => 86399], 200),
        'eu.api.blizzard.test/*' => Http::response(['equipped_item_level' => 282], 200),
    ]);

    $client = BlizzardClient::fromConfig();
    $client->profile('silvermoon', 'Sheday');
    $client->profile('silvermoon', 'Tute');
    $client->profile('twisting-nether', 'Argus');

    // OAuth hit exactly once, profile fetched three times.
    Http::assertSentCount(4);
    Http::assertSent(fn ($req) => $req->url() === 'https://oauth.battle.test/token');
});

it('re-fetches the token after forgetToken()', function () {
    Http::fakeSequence('oauth.battle.test/token')
        ->push(['access_token' => 'first', 'expires_in' => 86399], 200)
        ->push(['access_token' => 'second', 'expires_in' => 86399], 200);
    Http::fake([
        'eu.api.blizzard.test/*' => Http::response(['equipped_item_level' => 282], 200),
    ]);

    $client = BlizzardClient::fromConfig();
    $client->profile('silvermoon', 'Sheday');
    $client->forgetToken();
    $client->profile('silvermoon', 'Tute');

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/profile/wow/character/silvermoon/sheday')
        && $req->hasHeader('Authorization', 'Bearer first'));
    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/profile/wow/character/silvermoon/tute')
        && $req->hasHeader('Authorization', 'Bearer second'));
});

it('throws when client credentials are missing', function () {
    config(['blizzard.client_id' => '', 'blizzard.client_secret' => '']);
    $client = BlizzardClient::fromConfig();

    expect(fn () => $client->profile('silvermoon', 'Sheday'))
        ->toThrow(\RuntimeException::class, 'Battle.net client credentials are not configured');
});

it('throws when the OAuth endpoint returns a non-2xx', function () {
    Http::fake([
        'oauth.battle.test/token' => Http::response(['error' => 'invalid_client'], 401),
    ]);

    expect(fn () => BlizzardClient::fromConfig()->profile('silvermoon', 'Sheday'))
        ->toThrow(\RuntimeException::class, 'Battle.net OAuth failed: 401');
});

it('throws when the OAuth response has no access_token', function () {
    Http::fake([
        'oauth.battle.test/token' => Http::response(['expires_in' => 86399], 200),
    ]);

    expect(fn () => BlizzardClient::fromConfig()->profile('silvermoon', 'Sheday'))
        ->toThrow(\RuntimeException::class, 'no access_token');
});

it('rawurlencodes realm and character segments', function () {
    Http::fake([
        'oauth.battle.test/token' => Http::response(['access_token' => 'tok', 'expires_in' => 86399], 200),
        'eu.api.blizzard.test/*' => Http::response(['equipped_item_level' => 282], 200),
    ]);

    BlizzardClient::fromConfig()->profile('argent-dawn', 'Andrômeda');

    Http::assertSent(fn ($req) => str_contains(
        $req->url(),
        '/profile/wow/character/argent-dawn/andr%C3%B4meda'
    ));
});

it('attaches locale as a query parameter', function () {
    config(['blizzard.locale' => 'en_US']);
    Http::fake([
        'oauth.battle.test/token' => Http::response(['access_token' => 'tok', 'expires_in' => 86399], 200),
        'eu.api.blizzard.test/*' => Http::response(['equipped_item_level' => 282], 200),
    ]);

    BlizzardClient::fromConfig()->profile('silvermoon', 'Sheday');

    Http::assertSent(fn ($req) => str_contains($req->url(), 'locale=en_US'));
});

it('isConfigured tracks whether credentials are present', function () {
    expect(BlizzardClient::fromConfig()->isConfigured())->toBeTrue();

    config(['blizzard.client_id' => '', 'blizzard.client_secret' => '']);
    expect(BlizzardClient::fromConfig()->isConfigured())->toBeFalse();

    config(['blizzard.client_id' => 'x', 'blizzard.client_secret' => '']);
    expect(BlizzardClient::fromConfig()->isConfigured())->toBeFalse();
});
