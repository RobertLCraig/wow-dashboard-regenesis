<?php

use App\Support\SetMainMacroBuilder;

it('builds a single GRM.SetMain line per character name', function () {
    $out = SetMainMacroBuilder::build(['Ñýxx-Draenor']);
    expect($out['macros'])->toBe(['/run GRM.SetMain("Ñýxx-Draenor")']);
    expect($out['oversized'])->toBe([]);
});

it('joins multiple names with newlines until the 255-byte limit', function () {
    $out = SetMainMacroBuilder::build(['A-Realm', 'B-Realm']);
    expect($out['macros'])->toBe(["/run GRM.SetMain(\"A-Realm\")\n/run GRM.SetMain(\"B-Realm\")"]);
});

it('splits into multiple macros when total bytes exceed 255', function () {
    // 6 SetMain lines for "Looooooong-Realmname" each. Each line is
    // ~50 bytes; 6 lines plus newlines pushes past 255 → two macros.
    $names = array_map(fn ($i) => "Char{$i}xxxxxxxxxxxxx-Verylongrealm", range(1, 8));
    $out = SetMainMacroBuilder::build($names);
    expect(count($out['macros']))->toBeGreaterThan(1);
    foreach ($out['macros'] as $macro) {
        expect(strlen($macro) <= 255)->toBeTrue();
    }
});

it('reports a name as oversized when its single line cannot fit', function () {
    $name = str_repeat('x', 240) . '-Realm';
    $out = SetMainMacroBuilder::build([$name]);
    expect($out['oversized'])->toBe([$name]);
    expect($out['macros'])->toBe([]);
});

it('escapes embedded double quotes and backslashes in the Lua string', function () {
    // Defensive: WoW char names don't contain these but Lua escapes
    // are part of the contract for any future caller.
    $out = SetMainMacroBuilder::build(['Weird"-name\\Realm']);
    expect($out['macros'][0])->toBe('/run GRM.SetMain("Weird\\"-name\\\\Realm")');
});

it('skips empty / whitespace-only names', function () {
    $out = SetMainMacroBuilder::build(['', '  ', 'Real-Name']);
    expect($out['macros'])->toBe(['/run GRM.SetMain("Real-Name")']);
});
