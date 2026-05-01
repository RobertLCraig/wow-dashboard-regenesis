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

function mode(string $difficulty, int $completed, int $total, array $encounters = []): array
{
    return [
        'difficulty' => ['type' => $difficulty, 'name' => ucfirst(strtolower($difficulty))],
        'progress' => [
            'completed_count' => $completed,
            'total_count' => $total,
            'encounters' => $encounters,
        ],
    ];
}

function encounter(int $id, string $name, int $completedCount = 0, ?int $lastKillMs = null): array
{
    return [
        'encounter' => ['id' => $id, 'name' => $name],
        'completed_count' => $completedCount,
        'last_kill_timestamp' => $lastKillMs,
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

it('teamBossBreakdown rolls up boss-by-boss kills across team members', function () {
    // Two team members. Member A has the first three Heroic bosses;
    // Member B has the first two and the fifth. The team's combined
    // breakdown should mark four bosses cleared (1, 2, 3, 5), with
    // killers counts of 2, 2, 1, 0, 1 respectively.
    $a = snap([
        ['expansion' => ['id' => 503, 'name' => 'TWW'], 'instances' => [
            instance(1296, 'Manaforge', [
                mode('HEROIC', 3, 5, [
                    encounter(1, 'Plexus', 1, 1_700_000_000_000),
                    encounter(2, 'Loom', 1, 1_700_000_001_000),
                    encounter(3, 'Soulbinder', 1, 1_700_000_002_000),
                    encounter(4, 'Forgeweaver', 0),
                    encounter(5, 'Fractillus', 0),
                ]),
            ]),
        ]],
    ]);
    $b = snap([
        ['expansion' => ['id' => 503, 'name' => 'TWW'], 'instances' => [
            instance(1296, 'Manaforge', [
                mode('HEROIC', 3, 5, [
                    encounter(1, 'Plexus', 1, 1_700_000_500_000),
                    encounter(2, 'Loom', 1, 1_700_000_501_000),
                    encounter(3, 'Soulbinder', 0),
                    encounter(4, 'Forgeweaver', 0),
                    encounter(5, 'Fractillus', 1, 1_700_000_502_000),
                ]),
            ]),
        ]],
    ]);

    $out = (new RaidProgressionAnalyzer())->teamBossBreakdown(new Collection([$a, $b]), 'heroic');

    expect($out)->toHaveCount(1);
    $inst = $out[0];
    expect($inst['id'])->toBe(1296);
    expect($inst['name'])->toBe('Manaforge');
    expect($inst['difficulties'])->toHaveCount(1);
    $diff = $inst['difficulties'][0];
    expect($diff['type'])->toBe('HEROIC');
    expect($diff['short'])->toBe('H');
    expect($diff['killed'])->toBe(4);
    expect($diff['total'])->toBe(5);
    expect($diff['encounters'])->toHaveCount(5);
    expect($diff['encounters'][0])->toMatchArray(['id' => 1, 'name' => 'Plexus', 'killers' => 2]);
    expect($diff['encounters'][2])->toMatchArray(['id' => 3, 'name' => 'Soulbinder', 'killers' => 1]);
    expect($diff['encounters'][3])->toMatchArray(['id' => 4, 'name' => 'Forgeweaver', 'killers' => 0]);
    expect($diff['encounters'][4])->toMatchArray(['id' => 5, 'name' => 'Fractillus', 'killers' => 1]);
    // Last-kill timestamp picks the most recent across snapshots.
    expect($diff['encounters'][0]['last_kill_ms'])->toBe(1_700_000_500_000);
    expect($diff['encounters'][3]['last_kill_ms'])->toBeNull();
});

it('teamBossBreakdown caps a heroic team at heroic, dropping mythic kills entirely', function () {
    // Mirrors the production bug: a heroic team whose roster includes
    // a crossover with mythic kills shouldn't suddenly show mythic
    // pips. The breakdown is capped just like the headline rollup.
    $crossover = snap([
        ['expansion' => ['id' => 503], 'instances' => [
            instance(1296, 'Manaforge', [
                mode('HEROIC', 1, 2, [
                    encounter(1, 'Plexus', 1, 1_700_000_000_000),
                    encounter(2, 'Loom', 0),
                ]),
                mode('MYTHIC', 1, 2, [
                    encounter(1, 'Plexus', 1, 1_700_001_000_000),
                    encounter(2, 'Loom', 0),
                ]),
            ]),
        ]],
    ]);

    $out = (new RaidProgressionAnalyzer())->teamBossBreakdown(new Collection([$crossover]), 'heroic');

    expect($out)->toHaveCount(1);
    expect($out[0]['difficulties'])->toHaveCount(1);
    expect($out[0]['difficulties'][0]['type'])->toBe('HEROIC');
});

it('teamBossBreakdown shows mythic and heroic for a mythic team, mythic first', function () {
    $snap = snap([
        ['expansion' => ['id' => 503], 'instances' => [
            instance(1296, 'Manaforge', [
                mode('HEROIC', 2, 2, [
                    encounter(1, 'Plexus', 1, 1_700_000_000_000),
                    encounter(2, 'Loom', 1, 1_700_000_000_000),
                ]),
                mode('MYTHIC', 1, 2, [
                    encounter(1, 'Plexus', 1, 1_700_001_000_000),
                    encounter(2, 'Loom', 0),
                ]),
            ]),
        ]],
    ]);

    $out = (new RaidProgressionAnalyzer())->teamBossBreakdown(new Collection([$snap]), 'mythic');

    $diffs = $out[0]['difficulties'];
    expect($diffs)->toHaveCount(2);
    expect($diffs[0]['type'])->toBe('MYTHIC');
    expect($diffs[0]['killed'])->toBe(1);
    expect($diffs[1]['type'])->toBe('HEROIC');
    expect($diffs[1]['killed'])->toBe(2);
});

it('teamBossBreakdown orders instances newest expansion + instance first and skips empty difficulties', function () {
    $snap = snap([
        ['expansion' => ['id' => 502, 'name' => 'DF'], 'instances' => [
            instance(1208, 'Aberrus', [
                mode('HEROIC', 1, 1, [encounter(11, 'Kazzara', 1, 1_690_000_000_000)]),
            ]),
        ]],
        ['expansion' => ['id' => 503, 'name' => 'TWW'], 'instances' => [
            instance(1290, 'Nerub-ar', [
                mode('HEROIC', 1, 1, [encounter(21, 'Ulgrax', 1, 1_700_000_000_000)]),
            ]),
            instance(1296, 'Manaforge', [
                mode('HEROIC', 0, 1, [encounter(31, 'Plexus', 0)]),
                // Encounters list empty: should be dropped, not rendered as "0/0 M".
                mode('MYTHIC', 0, 0, []),
            ]),
        ]],
    ]);

    $out = (new RaidProgressionAnalyzer())->teamBossBreakdown(new Collection([$snap]), 'mythic');

    expect($out)->toHaveCount(3);
    expect($out[0]['name'])->toBe('Manaforge'); // newest expansion + newest instance
    expect($out[1]['name'])->toBe('Nerub-ar');
    expect($out[2]['name'])->toBe('Aberrus');
    // Manaforge's MYTHIC mode had no encounter rows, so it should be
    // dropped entirely - only the (no-kill) HEROIC pip row survives.
    expect($out[0]['difficulties'])->toHaveCount(1);
    expect($out[0]['difficulties'][0]['type'])->toBe('HEROIC');
    expect($out[0]['difficulties'][0]['killed'])->toBe(0);
});

it('teamBossBreakdown returns empty when the snapshot collection is empty', function () {
    $out = (new RaidProgressionAnalyzer())->teamBossBreakdown(new Collection(), 'mythic');
    expect($out)->toBe([]);
});
