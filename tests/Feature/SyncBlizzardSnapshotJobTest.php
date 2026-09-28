<?php

use App\Jobs\SyncBlizzardSnapshotJob;
use App\Models\Member;
use App\Services\Sync\SyncStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'blizzard.region' => 'eu',
        'blizzard.client_id' => 'test-client-id',
        'blizzard.client_secret' => 'test-client-secret',
        'blizzard.api_base_url' => 'https://eu.api.blizzard.test',
        'blizzard.oauth_token_url' => 'https://oauth.battle.test/token',
        'blizzard.namespace' => 'profile-eu',
        'blizzard.dynamic_namespace' => 'dynamic-eu',
        'blizzard.locale' => 'en_GB',
        'blizzard.timeout' => 5,
        'blizzard.token_cache_ttl' => 60,
        'blizzard.request_delay_ms' => 0,
        'blizzard.guild_realm_slug' => '',
        'blizzard.guild_name_slug' => '',
    ]);
    Cache::flush();
});

it('records the unchanged equipment count on the sync run', function () {
    Member::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'name' => 'Sheday-Silvermoon',
        'class' => 'PRIEST',
        'level' => 80,
        'rank_index' => 5,
        'realm_slug' => 'silvermoon',
        'status' => Member::STATUS_ACTIVE,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'last_online_at' => now(),
    ]);

    Http::fake([
        'oauth.battle.test/token' => Http::response(['access_token' => 'tok', 'expires_in' => 86399], 200),
        'eu.api.blizzard.test/profile/wow/character/silvermoon/sheday/equipment*' => Http::response([
            'equipped_item_level' => 282,
            'average_item_level' => 285,
            'equipped_items' => [['item' => ['id' => 100001], 'slot' => ['type' => 'HEAD']]],
        ], 200),
        '*' => Http::response([], 404),
    ]);

    // Second sweep sees the same gear, so it writes no new row for Sheday.
    (new SyncBlizzardSnapshotJob('Regenesis-Silvermoon'))->handle();
    (new SyncBlizzardSnapshotJob('Regenesis-Silvermoon'))->handle();

    $status = SyncStatus::get(SyncStatus::SOURCE_BLIZZARD);

    expect($status['status'])->toBe(SyncStatus::DONE);
    expect($status['summary'])->toHaveKey('equipment_unchanged');
    expect($status['summary']['equipment_matched'])->toBe(1);
    expect($status['summary']['equipment_unchanged'])->toBe(1);
});
