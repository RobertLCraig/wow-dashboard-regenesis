<?php

use App\Models\MemberRaidSnapshot;
use App\Services\Blizzard\RaidProgressionAnalyzer;
use Illuminate\Support\Collection;

function snap(array $expansions): MemberRaidSnapshot
{
    $m = new MemberRaidSnapshot();
    $m->expansions = $expansions;
    return $m;
}

function instance(int $id, string $name, array $modes = []): array
{
    return [
        'instance' => ['id' => $id, 'name' => $name],
        'modes' => $modes,
    ];
}

function mode(string $difficulty, int $completed, int $total): array
{
    return [
        'difficulty' => ['type' => $difficulty, 'name' => ucfirst(strtolower($difficulty))],
        'progress' => [
            'completed_count' => $completed,
            'total_count' => $total,
            'encounters' => [],
        ],
    ];
}

it('picks the highest-id expansion and within it the highest-id instance as current tier', function () {
    $a = snap([
        ['expansion' => ['id' => 500, 'name' => 'Old'], 'instances' => [instance(900, 'Old Raid', [])]],
        ['expansion' => ['id' => 503, 'name' => 'TWW'], 'instances' => [instance(1290, 'Nerub'), instance(1296, 'Manaforge', [])]],
    ]);
    $b = snap([
        ['expansion' => ['id' => 503, 'name' => 'TWW'], 'instances' => [instance(1296, 'Manaforge', [])]],
    ]);

    $tier = (new RaidProgressionAnalyzer())->currentTier(new Collection([$a, $b]));

    expect($tier)->toBe([
        'expansion_id' => 503,
        'expansion_name' => 'TWW',
        'instance_id' => 1296,
        'instance_name' => 'Manaforge',
    ]);
});

it('returns null when there are no expansions in any snapshot', function () {
    $r = (new RaidProgressionAnalyzer())->currentTier(new Collection([snap([]), snap([])]));
    expect($r)->toBeNull();
});

it('hasAotcOn requires every Heroic encounter cleared', function () {
    $cleared = snap([
        ['expansion' => ['id' => 503], 'instances' => [
            instance(1296, 'Manaforge', [mode('HEROIC', 8, 8)]),
        ]],
    ]);
    $partial = snap([
        ['expansion' => ['id' => 503], 'instances' => [
            instance(1296, 'Manaforge', [mode('HEROIC', 7, 8)]),
        ]],
    ]);
    $analyzer = new RaidProgressionAnalyzer();

    expect($analyzer->hasAotcOn($cleared, 1296))->toBeTrue();
    expect($analyzer->hasAotcOn($partial, 1296))->toBeFalse();
});

it('hasCeOn requires every Mythic encounter cleared', function () {
    $heroicOnly = snap([
        ['expansion' => ['id' => 503], 'instances' => [
            instance(1296, 'Manaforge', [
                mode('HEROIC', 8, 8),
                mode('MYTHIC', 5, 8),
            ]),
        ]],
    ]);
    $ceCleared = snap([
        ['expansion' => ['id' => 503], 'instances' => [
            instance(1296, 'Manaforge', [
                mode('HEROIC', 8, 8),
                mode('MYTHIC', 8, 8),
            ]),
        ]],
    ]);
    $analyzer = new RaidProgressionAnalyzer();

    expect($analyzer->hasCeOn($heroicOnly, 1296))->toBeFalse();
    expect($analyzer->hasCeOn($ceCleared, 1296))->toBeTrue();
});

it('returns false for AOTC/CE on instances the snapshot has no entry for', function () {
    $other = snap([
        ['expansion' => ['id' => 503], 'instances' => [
            instance(1290, 'Nerub', [mode('HEROIC', 8, 8)]),
        ]],
    ]);
    $analyzer = new RaidProgressionAnalyzer();

    expect($analyzer->hasAotcOn($other, 1296))->toBeFalse();
    expect($analyzer->hasCeOn($other, 1296))->toBeFalse();
});

it('returns false when total_count is zero (incomplete payload guard)', function () {
    $weird = snap([
        ['expansion' => ['id' => 503], 'instances' => [
            instance(1296, 'Manaforge', [mode('HEROIC', 0, 0)]),
        ]],
    ]);
    expect((new RaidProgressionAnalyzer())->hasAotcOn($weird, 1296))->toBeFalse();
});
