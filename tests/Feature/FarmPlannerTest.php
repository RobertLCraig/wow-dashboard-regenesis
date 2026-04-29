<?php

use App\Models\Member;
use App\Models\MemberSocialSnapshot;
use App\Models\Snapshot;
use App\Models\User;
use App\Services\Blizzard\CollectionsAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

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
    ]);
});

function farmMember(string $name): Member
{
    return Member::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'name' => $name,
        'class' => 'PRIEST',
        'level' => 80,
        'rank_index' => 5,
        'rank_name' => 'Member',
        'status' => Member::STATUS_ACTIVE,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);
}

function farmOfficer(): User
{
    return User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);
}

function farmSnap(int $memberId, int $snapshotId, array $payloads): MemberSocialSnapshot
{
    return MemberSocialSnapshot::query()->create(array_replace([
        'snapshot_id' => $snapshotId,
        'member_id' => $memberId,
        'character_media' => null,
        'achievements' => null,
        'mounts' => null,
        'pets' => null,
        'toys' => null,
        'transmogs' => null,
    ], $payloads));
}

it('analyzer detects mount ownership through the mounts.{n}.mount.id path', function () {
    $snap = new MemberSocialSnapshot();
    $snap->mounts = ['mounts' => [
        ['mount' => ['id' => 1234, 'name' => 'Test Mount']],
        ['mount' => ['id' => 5678, 'name' => 'Other Mount']],
    ]];

    $analyzer = new CollectionsAnalyzer();
    expect($analyzer->memberHas($snap, CollectionsAnalyzer::TYPE_MOUNT, 1234))->toBeTrue();
    expect($analyzer->memberHas($snap, CollectionsAnalyzer::TYPE_MOUNT, 9999))->toBeFalse();
});

it('analyzer detects pet ownership through species.id and toy through toy.id', function () {
    $snap = new MemberSocialSnapshot();
    $snap->pets = ['pets' => [['species' => ['id' => 42, 'name' => 'P']]]];
    $snap->toys = ['toys' => [['toy' => ['id' => 99, 'name' => 'T']]]];

    $analyzer = new CollectionsAnalyzer();
    expect($analyzer->memberHas($snap, CollectionsAnalyzer::TYPE_PET, 42))->toBeTrue();
    expect($analyzer->memberHas($snap, CollectionsAnalyzer::TYPE_TOY, 99))->toBeTrue();
    expect($analyzer->memberHas($snap, CollectionsAnalyzer::TYPE_PET, 99))->toBeFalse();
});

it('gap() buckets members into has / missing / no_data with coverage percent', function () {
    $hasIt = farmMember('Hasit-Silvermoon');
    $needs = farmMember('Needs-Silvermoon');
    $unknown = farmMember('Unknown-Silvermoon');

    $snapshot = Snapshot::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'captured_at' => now(),
        'source' => Snapshot::SOURCE_BLIZZARD_SOCIAL,
        'payload_hash' => 'h-farm',
    ]);
    $snaps = collect([
        $hasIt->id => farmSnap($hasIt->id, $snapshot->id, [
            'mounts' => ['mounts' => [['mount' => ['id' => 1234]]]],
        ]),
        $needs->id => farmSnap($needs->id, $snapshot->id, [
            'mounts' => ['mounts' => [['mount' => ['id' => 9999]]]],
        ]),
        // Unknown has no MemberSocialSnapshot row at all.
    ]);

    $members = collect([$hasIt, $needs, $unknown]);
    $gap = (new CollectionsAnalyzer())->gap($members, $snaps, CollectionsAnalyzer::TYPE_MOUNT, 1234);

    expect($gap['has'])->toHaveCount(1)->and($gap['has'][0]['name'])->toBe('Hasit-Silvermoon');
    expect($gap['missing'])->toHaveCount(1)->and($gap['missing'][0]['name'])->toBe('Needs-Silvermoon');
    expect($gap['no_data'])->toHaveCount(1)->and($gap['no_data'][0]['name'])->toBe('Unknown-Silvermoon');
    expect($gap['coverage_pct'])->toBe(50);
});

it('farm-planner page renders the empty hint when no type+id submitted', function () {
    $resp = $this->actingAs(farmOfficer())->get('/farm-planner');

    $resp->assertOk()
        ->assertSee('Farm planner')
        ->assertSee('Pick a type and an ID');
});

it('farm-planner page renders has/missing buckets for a real query', function () {
    $hasIt = farmMember('Wins-Silvermoon');
    $needs = farmMember('Wants-Silvermoon');

    $snapshot = Snapshot::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'captured_at' => now(),
        'source' => Snapshot::SOURCE_BLIZZARD_SOCIAL,
        'payload_hash' => 'h-farm-page',
    ]);
    farmSnap($hasIt->id, $snapshot->id, [
        'mounts' => ['mounts' => [['mount' => ['id' => 7777]]]],
    ]);
    farmSnap($needs->id, $snapshot->id, [
        'mounts' => ['mounts' => []],
    ]);

    $resp = $this->actingAs(farmOfficer())->get('/farm-planner?type=mount&id=7777');

    $resp->assertOk()
        ->assertSee('Wins-Silvermoon')
        ->assertSee('Wants-Silvermoon')
        ->assertSee('still needs it')
        ->assertSee('50%');
});

it('non-officer is 403d from the farm planner', function () {
    $member = User::factory()->create(['tier' => 'member', 'last_role_check_at' => now()]);
    $this->actingAs($member)->get('/farm-planner')->assertForbidden();
});
