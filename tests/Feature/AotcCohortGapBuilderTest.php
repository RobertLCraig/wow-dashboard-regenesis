<?php

use App\Models\Member;
use App\Models\MemberRaidSnapshot;
use App\Services\Blizzard\AotcCohortGapBuilder;
use App\Services\Blizzard\RaidProgressionAnalyzer;
use Illuminate\Support\Collection;

/**
 * Pure unit coverage for the alt-cohort rollup logic. No DB needed:
 * everything is in-memory model instances + collections, so these
 * tests stay isolated from the broader dashboard rendering path.
 */
function aotcCohortMember(int $id, string $name, string $class = 'PRIEST', ?int $altGroupId = null, ?int $mainMemberId = null): Member
{
    $m = new Member();
    $m->id = $id;
    $m->name = $name;
    $m->class = $class;
    $m->alt_group_id = $altGroupId;
    $m->main_member_id = $mainMemberId;
    return $m;
}

function aotcRaidSnap(int $memberId, int $instanceId, string $difficulty, int $completed, int $total): MemberRaidSnapshot
{
    $m = new MemberRaidSnapshot();
    $m->member_id = $memberId;
    $m->expansions = [
        [
            'expansion' => ['id' => 503, 'name' => 'TWW'],
            'instances' => [[
                'instance' => ['id' => $instanceId, 'name' => 'Manaforge'],
                'modes' => [[
                    'difficulty' => ['type' => $difficulty],
                    'progress' => ['completed_count' => $completed, 'total_count' => $total, 'encounters' => []],
                ]],
            ]],
        ],
    ];
    return $m;
}

function aotcBuildCohortGap(Collection $members, Collection $snaps): ?array
{
    return (new AotcCohortGapBuilder(new RaidProgressionAnalyzer()))->build($members, $snaps);
}

it('counts a one-character cohort with AOTC as cleared', function () {
    $solo = aotcCohortMember(1, 'Solo-Silvermoon');
    $snap = aotcRaidSnap(1, 1296, 'HEROIC', 8, 8);

    $gap = aotcBuildCohortGap(new Collection([$solo]), new Collection([$snap]));

    expect($gap)->not->toBeNull();
    expect($gap['active_count'])->toBe(1)
        ->and($gap['active_member_count'])->toBe(1)
        ->and($gap['has_aotc'])->toHaveCount(1)
        ->and($gap['missing_aotc'])->toBeEmpty();
    expect($gap['has_aotc'][0])->toMatchArray(['name' => 'Solo-Silvermoon', 'alts' => []]);
});

it('rolls a cohort up to the declared main when only the alt has AOTC', function () {
    // alt_group 10 with main_member_id pointing at the warrior main.
    // Healer alt has AOTC, warrior main does not. Officers should see
    // one AOTC-cleared player here, not "warrior is missing AOTC".
    $main = aotcCohortMember(1, 'Tankmain-Silvermoon', 'WARRIOR', altGroupId: 10, mainMemberId: null);
    $alt  = aotcCohortMember(2, 'Healalt-Silvermoon', 'PRIEST',  altGroupId: 10, mainMemberId: 1);

    $mainSnap = aotcRaidSnap(1, 1296, 'HEROIC', 5, 8); // not yet
    $altSnap  = aotcRaidSnap(2, 1296, 'HEROIC', 8, 8); // cleared

    $gap = aotcBuildCohortGap(new Collection([$main, $alt]), new Collection([$mainSnap, $altSnap]));

    expect($gap['active_count'])->toBe(1)
        ->and($gap['active_member_count'])->toBe(2)
        ->and($gap['has_aotc'])->toHaveCount(1)
        ->and($gap['missing_aotc'])->toBeEmpty();
    expect($gap['has_aotc'][0])->toMatchArray([
        'name' => 'Tankmain-Silvermoon',
        'class' => 'WARRIOR',
        'alts' => ['Healalt-Silvermoon'],
    ]);
});

it('keeps a cohort in missing-AOTC when none of its characters have it', function () {
    $main = aotcCohortMember(1, 'Twomains-Silvermoon', 'PALADIN', altGroupId: 11, mainMemberId: null);
    $alt  = aotcCohortMember(2, 'Twomains-Alt',         'PRIEST',  altGroupId: 11, mainMemberId: 1);

    $gap = aotcBuildCohortGap(
        new Collection([$main, $alt]),
        new Collection([
            aotcRaidSnap(1, 1296, 'HEROIC', 4, 8),
            aotcRaidSnap(2, 1296, 'HEROIC', 6, 8),
        ]),
    );

    expect($gap['missing_aotc'])->toHaveCount(1)
        ->and($gap['has_aotc'])->toBeEmpty();
    expect($gap['missing_aotc'][0])->toMatchArray([
        'name' => 'Twomains-Silvermoon',
        'alts' => ['Twomains-Alt'],
    ]);
});

it('promotes CE only when a cohort cleared every Mythic boss', function () {
    $solo = aotcCohortMember(1, 'Mythicsolo-Silvermoon', 'DRUID');
    $snap = new MemberRaidSnapshot();
    $snap->member_id = 1;
    $snap->expansions = [[
        'expansion' => ['id' => 503, 'name' => 'TWW'],
        'instances' => [[
            'instance' => ['id' => 1296, 'name' => 'Manaforge'],
            'modes' => [
                ['difficulty' => ['type' => 'HEROIC'], 'progress' => ['completed_count' => 8, 'total_count' => 8, 'encounters' => []]],
                ['difficulty' => ['type' => 'MYTHIC'], 'progress' => ['completed_count' => 8, 'total_count' => 8, 'encounters' => []]],
            ],
        ]],
    ]];

    $gap = aotcBuildCohortGap(new Collection([$solo]), new Collection([$snap]));

    expect($gap['has_aotc'])->toHaveCount(1)
        ->and($gap['has_ce'])->toHaveCount(1);
});

it('treats grouped members with no declared main as a single cohort labelled by first character', function () {
    // Group exists but GRM never picked one as the main: both rows
    // have main_member_id = null. Builder should still collapse them
    // into one cohort and use the first (alphabetical) name.
    $a = aotcCohortMember(1, 'Acharone-Silvermoon', altGroupId: 12, mainMemberId: null);
    $b = aotcCohortMember(2, 'Bcharone-Silvermoon', altGroupId: 12, mainMemberId: null);

    $gap = aotcBuildCohortGap(
        new Collection([$a, $b]),
        new Collection([
            aotcRaidSnap(1, 1296, 'HEROIC', 8, 8),
            aotcRaidSnap(2, 1296, 'HEROIC', 5, 8),
        ]),
    );

    expect($gap['active_count'])->toBe(1)
        ->and($gap['has_aotc'])->toHaveCount(1);
    expect($gap['has_aotc'][0])->toMatchArray([
        'name' => 'Acharone-Silvermoon',
        'alts' => ['Bcharone-Silvermoon'],
    ]);
});

it('returns null when no Blizzard raid snapshots are available', function () {
    $member = aotcCohortMember(1, 'Anyone-Silvermoon');

    $gap = aotcBuildCohortGap(new Collection([$member]), new Collection([]));

    expect($gap)->toBeNull();
});

it('returns null when the active roster is empty even with stray snapshots', function () {
    $stray = aotcRaidSnap(99, 1296, 'HEROIC', 8, 8);

    $gap = aotcBuildCohortGap(new Collection(), new Collection([$stray]));

    expect($gap)->toBeNull();
});

it('handles members with no snapshot row by leaving them in missing-AOTC', function () {
    // Common when blizzard:pull-raids has not yet covered the new
    // recruit. They should not silently count as cleared.
    $main = aotcCohortMember(1, 'Recruit-Silvermoon');

    $gap = aotcBuildCohortGap(new Collection([$main]), new Collection([
        // Stray snapshot for a different member so the analyzer can
        // resolve the current tier.
        aotcRaidSnap(2, 1296, 'HEROIC', 8, 8),
    ]));

    expect($gap['missing_aotc'])->toHaveCount(1)
        ->and($gap['has_aotc'])->toBeEmpty();
});
