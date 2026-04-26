<?php

use App\Jobs\SyncRaiderioSnapshotJob;
use App\Models\Member;
use App\Models\Snapshot;
use App\Models\User;
use App\Services\Sync\SyncStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'grm.guild_key' => 'Regenesis-Silvermoon',
        'discord.guild_id' => '1247256415542841416',
        'discord.roles' => [
            'gm' => '1247279261434384415',
            'big6' => '1490762074584780951',
            'officer' => '1247278529163296789',
        ],
        'raiderio.base_url' => 'https://raider.io.test/api/v1',
        'raiderio.request_delay_ms' => 0,
        'raiderio.profile_fields' => ['gear', 'raid_progression', 'mythic_plus_scores_by_season:current', 'mythic_plus_weekly_highest_level_runs'],
    ]);
    Cache::flush();
});

function syncOfficer(): User
{
    return User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);
}

it('renders the sync dashboard for an officer with all four source panels', function () {
    $this->actingAs(officer())
        ->get('/admin/sync')
        ->assertOk()
        ->assertSee('GRM (in-game)')
        ->assertSee('Raid-Helper')
        ->assertSee('Wowaudit')
        ->assertSee('Raider.IO');
});

it('403s a non-officer from the sync dashboard', function () {
    $u = User::factory()->create(['tier' => null, 'last_role_check_at' => now()]);
    $this->actingAs($u)->get('/admin/sync')->assertStatus(403);
});

it('shows last snapshot timestamp for sources that have one', function () {
    Snapshot::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'captured_at' => now()->subHour(),
        'source' => Snapshot::SOURCE_GRM,
        'payload_hash' => 'h1',
        'member_count' => 776,
    ]);

    $this->actingAs(officer())
        ->get('/admin/sync')
        ->assertOk()
        ->assertSee('776 members');
});

it('clicking RIO sync dispatches the job afterResponse and writes queued state', function () {
    Bus::fake();

    Member::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'name' => 'Sheday-Silvermoon',
        'level' => 80, 'rank_index' => 5,
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);

    $this->actingAs(officer())
        ->post('/admin/raiderio/sync')
        ->assertRedirect('/admin/sync');

    Bus::assertDispatchedAfterResponse(SyncRaiderioSnapshotJob::class);

    $state = SyncStatus::get(SyncStatus::SOURCE_RAIDERIO);
    expect($state['status'])->toBe('queued');
});

it('the page meta-refreshes while a sync is queued or running', function () {
    SyncStatus::set(SyncStatus::SOURCE_RAIDERIO, [
        'status' => 'running',
        'started_at' => now()->toIso8601String(),
        'started_by_user_id' => null,
        'finished_at' => null,
        'summary' => null,
        'error' => null,
    ]);

    $this->actingAs(officer())
        ->get('/admin/sync')
        ->assertOk()
        ->assertSee('http-equiv="refresh"', false);
});

it('does not meta-refresh when no source is mid-sync', function () {
    $this->actingAs(officer())
        ->get('/admin/sync')
        ->assertOk()
        ->assertDontSee('http-equiv="refresh"', false);
});

it('renders done summary with member counts when the last sync completed', function () {
    SyncStatus::set(SyncStatus::SOURCE_RAIDERIO, [
        'status' => 'done',
        'started_at' => now()->subSeconds(15)->toIso8601String(),
        'started_by_user_id' => 1,
        'finished_at' => now()->toIso8601String(),
        'summary' => ['members_queried' => 50, 'matched' => 47, 'missing' => 3, 'errored' => 0, 'snapshot_id' => 12],
        'error' => null,
    ]);

    $this->actingAs(officer())
        ->get('/admin/sync')
        ->assertOk()
        ->assertSee('matched: 47')
        ->assertSee('missing: 3');
});

it('renders failed state with the error message', function () {
    SyncStatus::set(SyncStatus::SOURCE_RAIDERIO, [
        'status' => 'failed',
        'started_at' => now()->toIso8601String(),
        'started_by_user_id' => null,
        'finished_at' => now()->toIso8601String(),
        'summary' => null,
        'error' => 'connection refused: raider.io',
    ]);

    $this->actingAs(officer())
        ->get('/admin/sync')
        ->assertOk()
        ->assertSee('connection refused');
});

it('GRM upload accepts a parseable .lua file and writes a snapshot', function () {
    Storage::fake('local');
    Bus::fake(); // The IngestSnapshotJob dispatch shouldn't actually run normalizer here

    $lua = <<<'LUA'
GRM_GuildMemberHistory_Save = {
  ["Regenesis-Silvermoon"] = {
    ["Heroguy-Silvermoon"] = {
      ["GUID"] = "Player-3391-AAA",
      ["class"] = "PRIEST",
      ["level"] = 80,
      ["rankName"] = "Heroic Raider",
      ["rankIndex"] = 4,
    },
  },
};
LUA;

    $file = UploadedFile::fake()->createWithContent('GRM.lua', $lua);

    $this->actingAs(officer())
        ->post('/admin/sync/grm', ['grm_file' => $file])
        ->assertRedirect('/admin/sync');

    expect(Snapshot::query()->where('source', Snapshot::SOURCE_GRM)->count())->toBe(1);

    $state = SyncStatus::get(SyncStatus::SOURCE_GRM);
    expect($state['status'])->toBe('done');
    expect($state['summary']['snapshot_id'])->toBeInt();
});

it('GRM upload rejects a malformed .lua file with a clean error', function () {
    Storage::fake('local');

    $file = UploadedFile::fake()->createWithContent('broken.lua', 'this is not lua at all');

    $this->actingAs(officer())
        ->post('/admin/sync/grm', ['grm_file' => $file])
        ->assertRedirect('/admin/sync')
        ->assertSessionHasErrors('grm_upload');

    $state = SyncStatus::get(SyncStatus::SOURCE_GRM);
    expect($state['status'])->toBe('failed');
});

it('GRM upload deduplicates a second upload of the same file', function () {
    Storage::fake('local');
    Bus::fake();

    $lua = "GRM_GuildMemberHistory_Save = { [\"Regenesis-Silvermoon\"] = {} };";
    $file = UploadedFile::fake()->createWithContent('GRM.lua', $lua);

    $u = officer();
    $this->actingAs($u)->post('/admin/sync/grm', ['grm_file' => $file])->assertRedirect();

    $file2 = UploadedFile::fake()->createWithContent('GRM.lua', $lua);
    $this->actingAs($u)
        ->post('/admin/sync/grm', ['grm_file' => $file2])
        ->assertRedirect()
        ->assertSessionHas('status', fn ($s) => str_contains($s, 'matches an existing snapshot'));

    expect(Snapshot::query()->where('source', Snapshot::SOURCE_GRM)->count())->toBe(1);
});
