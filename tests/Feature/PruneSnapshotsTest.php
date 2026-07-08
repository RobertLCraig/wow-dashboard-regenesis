<?php

use App\Models\Member;
use App\Models\MemberEquipmentSnapshot;
use App\Models\MemberSnapshot;
use App\Models\Snapshot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'grm.guild_key' => 'Regenesis-Silvermoon',
        'snapshots.retention_days' => 30,
    ]);
});

function snapMember(string $name): Member
{
    return Member::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'name' => $name,
        'class' => 'PRIEST',
        'level' => 80,
        'rank_index' => 5,
        'status' => Member::STATUS_ACTIVE,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'last_online_at' => now(),
    ]);
}

function makeSnap(CarbonImmutable $capturedAt, string $source = 'raiderio'): Snapshot
{
    return Snapshot::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'captured_at' => $capturedAt,
        'source' => $source,
        // Unique per row to satisfy the (guild_key, source, payload_hash) index.
        'payload_hash' => substr(hash('sha256', $source.$capturedAt->toISOString().uniqid('', true)), 0, 64),
        'member_count' => 1,
    ]);
}

function memberSnapRow(int $memberId, Snapshot $snap): MemberSnapshot
{
    return MemberSnapshot::query()->create([
        'member_id' => $memberId,
        'snapshot_id' => $snap->id,
        'level' => 80,
    ]);
}

it('keeps each member\'s latest row per source and prunes older history', function () {
    $now = CarbonImmutable::now();
    $m = snapMember('Sheday-Silvermoon');

    $recent = memberSnapRow($m->id, makeSnap($now->subDays(5), 'raiderio'));   // within window -> keep
    $old = memberSnapRow($m->id, makeSnap($now->subDays(45), 'raiderio'));     // old + superseded -> prune
    $older = memberSnapRow($m->id, makeSnap($now->subDays(90), 'raiderio'));   // old + superseded -> prune

    $this->artisan('snapshots:prune')->assertExitCode(0);

    $ids = MemberSnapshot::query()->pluck('id')->all();
    expect($ids)->toContain($recent->id)
        ->not->toContain($old->id)
        ->not->toContain($older->id);
});

it('never drops a member\'s latest row, even far past the window', function () {
    $now = CarbonImmutable::now();
    $m = snapMember('Stale-Silvermoon');

    $latest = memberSnapRow($m->id, makeSnap($now->subDays(200), 'raiderio'));  // ancient but the latest -> keep
    $ancient = memberSnapRow($m->id, makeSnap($now->subDays(300), 'raiderio')); // superseded -> prune

    $this->artisan('snapshots:prune')->assertExitCode(0);

    expect(MemberSnapshot::query()->pluck('id')->all())
        ->toContain($latest->id)
        ->not->toContain($ancient->id);
});

it('protects the latest row of each source independently', function () {
    $now = CarbonImmutable::now();
    $m = snapMember('Multi-Silvermoon');

    $rio = memberSnapRow($m->id, makeSnap($now->subDays(100), 'raiderio'));    // latest raiderio (old) -> keep
    $grm = memberSnapRow($m->id, makeSnap($now->subDays(90), 'grm'));          // latest grm (old) -> keep
    $oldRio = memberSnapRow($m->id, makeSnap($now->subDays(120), 'raiderio')); // superseded raiderio -> prune

    $this->artisan('snapshots:prune')->assertExitCode(0);

    $ids = MemberSnapshot::query()->pluck('id')->all();
    expect($ids)->toContain($rio->id)->toContain($grm->id)->not->toContain($oldRio->id);
});

it('respects the --days override', function () {
    $now = CarbonImmutable::now();
    $m = snapMember('Override-Silvermoon');
    $keep = memberSnapRow($m->id, makeSnap($now->subDays(5), 'raiderio'));
    $drop = memberSnapRow($m->id, makeSnap($now->subDays(20), 'raiderio')); // >10 days + superseded

    $this->artisan('snapshots:prune', ['--days' => 10])->assertExitCode(0);

    expect(MemberSnapshot::query()->pluck('id')->all())
        ->toContain($keep->id)->not->toContain($drop->id);
});

it('--dry-run reports without deleting', function () {
    $now = CarbonImmutable::now();
    $m = snapMember('Dry-Silvermoon');
    memberSnapRow($m->id, makeSnap($now->subDays(5), 'raiderio'));
    memberSnapRow($m->id, makeSnap($now->subDays(90), 'raiderio'));

    $this->artisan('snapshots:prune', ['--dry-run' => true])
        ->expectsOutputToContain('[dry-run]')
        ->assertExitCode(0);

    expect(MemberSnapshot::query()->count())->toBe(2);
});

it('disables pruning entirely when retention is 0', function () {
    config(['snapshots.retention_days' => 0]);
    $now = CarbonImmutable::now();
    $m = snapMember('Never-Silvermoon');
    memberSnapRow($m->id, makeSnap($now->subDays(500), 'raiderio'));
    memberSnapRow($m->id, makeSnap($now->subDays(400), 'raiderio'));

    $this->artisan('snapshots:prune')
        ->expectsOutputToContain('Retention disabled')
        ->assertExitCode(0);

    expect(MemberSnapshot::query()->count())->toBe(2);
});

it('limits to the named table when --table is given', function () {
    $now = CarbonImmutable::now();
    $m = snapMember('Scoped-Silvermoon');

    memberSnapRow($m->id, makeSnap($now->subDays(5), 'raiderio'));
    $msDrop = memberSnapRow($m->id, makeSnap($now->subDays(90), 'raiderio')); // would prune if in scope

    MemberEquipmentSnapshot::query()->create([
        'member_id' => $m->id,
        'snapshot_id' => makeSnap($now->subDays(5), 'blizzard_equipment')->id,
        'pieces' => [],
    ]);
    $eqDrop = MemberEquipmentSnapshot::query()->create([
        'member_id' => $m->id,
        'snapshot_id' => makeSnap($now->subDays(90), 'blizzard_equipment')->id,
        'pieces' => [],
    ]);

    $this->artisan('snapshots:prune', ['--table' => ['member_snapshots']])->assertExitCode(0);

    // member_snapshots swept; equipment untouched because it was out of scope.
    expect(MemberSnapshot::query()->pluck('id')->all())->not->toContain($msDrop->id);
    expect(MemberEquipmentSnapshot::query()->pluck('id')->all())->toContain($eqDrop->id);
});
