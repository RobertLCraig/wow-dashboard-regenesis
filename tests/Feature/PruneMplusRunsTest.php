<?php

use App\Models\Member;
use App\Models\MemberMplusRun;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['grm.guild_key' => 'Regenesis-Silvermoon']);
});

function makePruneMember(): Member
{
    return Member::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'name' => 'Sheday-Silvermoon',
        'class' => 'PRIEST',
        'level' => 80,
        'rank_index' => 5,
        'status' => Member::STATUS_ACTIVE,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'last_online_at' => now(),
    ]);
}

function makeRun(int $memberId, CarbonImmutable $completedAt, int $level = 10): MemberMplusRun
{
    return MemberMplusRun::query()->create([
        'member_id' => $memberId,
        'completed_at' => $completedAt,
        'mythic_level' => $level,
        'dungeon_short_name' => 'HoA',
        'dungeon_name' => 'Halls of Atonement',
        'num_keystone_upgrades' => 1,
        'source' => MemberMplusRun::SOURCE_RECENT,
        'first_seen_at' => $completedAt,
        'last_seen_at' => $completedAt,
    ]);
}

it('drops runs older than the retention window and keeps recent ones', function () {
    config(['raiderio.runs_retention_days' => 180]);
    $m = makePruneMember();
    $now = CarbonImmutable::now();

    makeRun($m->id, $now->subDays(10), 14);   // recent - keep
    makeRun($m->id, $now->subDays(120), 12);  // within 180d - keep
    makeRun($m->id, $now->subDays(200), 10);  // outside 180d - drop
    makeRun($m->id, $now->subDays(400), 8);   // way outside - drop

    expect(MemberMplusRun::query()->count())->toBe(4);

    $this->artisan('mplus:prune-runs')->assertExitCode(0);

    $remaining = MemberMplusRun::query()->orderBy('completed_at')->get();
    expect($remaining)->toHaveCount(2);
    expect($remaining->pluck('mythic_level')->all())->toBe([12, 14]);
});

it('respects --days override even when config is set differently', function () {
    config(['raiderio.runs_retention_days' => 365]);
    $m = makePruneMember();
    $now = CarbonImmutable::now();

    makeRun($m->id, $now->subDays(50), 14);
    makeRun($m->id, $now->subDays(100), 12);

    $this->artisan('mplus:prune-runs', ['--days' => 60])->assertExitCode(0);

    expect(MemberMplusRun::query()->count())->toBe(1);
    expect(MemberMplusRun::query()->first()->mythic_level)->toBe(14);
});

it('--dry-run reports without deleting', function () {
    config(['raiderio.runs_retention_days' => 30]);
    $m = makePruneMember();
    makeRun($m->id, CarbonImmutable::now()->subDays(60), 10);

    $this->artisan('mplus:prune-runs', ['--dry-run' => true])
        ->expectsOutputToContain('[dry-run]')
        ->assertExitCode(0);

    expect(MemberMplusRun::query()->count())->toBe(1);
});

it('disables pruning entirely when retention is 0', function () {
    config(['raiderio.runs_retention_days' => 0]);
    $m = makePruneMember();
    makeRun($m->id, CarbonImmutable::now()->subDays(1000), 10);

    $this->artisan('mplus:prune-runs')
        ->expectsOutputToContain('Retention disabled')
        ->assertExitCode(0);

    expect(MemberMplusRun::query()->count())->toBe(1);
});

it('is a no-op when nothing is older than the cutoff', function () {
    config(['raiderio.runs_retention_days' => 180]);
    $m = makePruneMember();
    makeRun($m->id, CarbonImmutable::now()->subDays(30), 14);

    $this->artisan('mplus:prune-runs')
        ->expectsOutputToContain('No runs older than')
        ->assertExitCode(0);

    expect(MemberMplusRun::query()->count())->toBe(1);
});
