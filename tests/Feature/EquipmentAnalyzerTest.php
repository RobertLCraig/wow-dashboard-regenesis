<?php

use App\Models\MemberEquipmentSnapshot;
use App\Services\Blizzard\EquipmentAnalyzer;

function piece(string $slotType, array $overrides = []): array
{
    return array_replace_recursive([
        'slot' => ['type' => $slotType],
        'enchantments' => [],
        'sockets' => [],
        'item' => ['id' => 100000],
    ], $overrides);
}

function snapWithPieces(array $pieces, ?int $equippedIlvl = 282): MemberEquipmentSnapshot
{
    $m = new MemberEquipmentSnapshot();
    $m->equipped_ilvl = $equippedIlvl;
    $m->pieces = $pieces;
    return $m;
}

it('reports zero issues when every enchantable slot has an enchant and no empty sockets', function () {
    $pieces = [
        piece('CHEST', ['enchantments' => [['enchantment_id' => 100]]]),
        piece('WRIST', ['enchantments' => [['enchantment_id' => 101]]]),
        piece('LEGS', ['enchantments' => [['enchantment_id' => 102]]]),
        piece('FEET', ['enchantments' => [['enchantment_id' => 103]]]),
        piece('BACK', ['enchantments' => [['enchantment_id' => 104]]]),
        piece('FINGER_1', ['enchantments' => [['enchantment_id' => 105]]]),
        piece('FINGER_2', ['enchantments' => [['enchantment_id' => 106]]]),
        piece('MAIN_HAND', ['enchantments' => [['enchantment_id' => 107]]]),
        piece('HEAD'),
        piece('NECK', ['sockets' => [['item' => ['id' => 213743]]]]),
    ];

    $r = (new EquipmentAnalyzer())->analyze(snapWithPieces($pieces));

    expect($r['missing_enchants'])->toBe([]);
    expect($r['empty_sockets'])->toBe([]);
    expect($r['total_issues'])->toBe(0);
    expect($r['equipped_ilvl'])->toBe(282);
    expect($r['pieces_count'])->toBe(10);
});

it('flags every enchantable slot that has no enchantment_id', function () {
    $pieces = [
        piece('CHEST'),         // no enchantments at all
        piece('LEGS', ['enchantments' => [['enchantment_id' => 0]]]), // zero id
        piece('FEET', ['enchantments' => [['something_else' => 1]]]), // no id key
        piece('MAIN_HAND', ['enchantments' => [['enchantment_id' => 99]]]),
    ];

    $r = (new EquipmentAnalyzer())->analyze(snapWithPieces($pieces));

    expect($r['missing_enchants'])->toBe(['CHEST', 'LEGS', 'FEET']);
    expect($r['total_issues'])->toBe(3);
});

it('does not flag slots that are not in the enchantable list', function () {
    $pieces = [
        piece('HEAD'),
        piece('SHOULDER'),
        piece('WAIST'),
        piece('HANDS'),
        piece('TRINKET_1'),
        piece('TRINKET_2'),
        piece('NECK'),
        piece('OFF_HAND'),
    ];

    $r = (new EquipmentAnalyzer())->analyze(snapWithPieces($pieces));

    expect($r['missing_enchants'])->toBe([]);
    expect($r['total_issues'])->toBe(0);
});

it('counts every empty socket on every slot', function () {
    $pieces = [
        piece('NECK', ['sockets' => [['item' => null], ['item' => ['id' => 0]]]]),
        piece('HEAD', ['sockets' => [['item' => ['id' => 213743]], ['item' => null]]]),
    ];

    $r = (new EquipmentAnalyzer())->analyze(snapWithPieces($pieces));

    expect($r['empty_sockets'])->toBe(['NECK', 'NECK', 'HEAD']);
    expect($r['total_issues'])->toBe(3);
});

it('handles missing snapshot, missing pieces, and malformed pieces gracefully', function () {
    $analyzer = new EquipmentAnalyzer();

    expect($analyzer->analyze(null)['total_issues'])->toBe(0);

    $emptySnap = snapWithPieces([], 0);
    expect($analyzer->analyze($emptySnap)['total_issues'])->toBe(0);

    $malformed = snapWithPieces([
        ['no_slot' => true],
        piece('CHEST'),
        ['slot' => null],
    ]);
    $r = $analyzer->analyze($malformed);
    expect($r['missing_enchants'])->toBe(['CHEST']);
});
