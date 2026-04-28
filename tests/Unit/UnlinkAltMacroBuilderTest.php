<?php

use App\Support\UnlinkAltMacroBuilder;

it('builds a single RemovePlayerFromAltGroup line per name', function () {
    $out = UnlinkAltMacroBuilder::build(['Sheday-Silvermoon']);
    expect($out['macros'])->toBe(['/run GRM.RemovePlayerFromAltGroup("Sheday-Silvermoon")']);
    expect($out['oversized'])->toBe([]);
});

it('joins multiple names with newlines until the 255-byte limit', function () {
    $out = UnlinkAltMacroBuilder::build(['A-Realm', 'B-Realm']);
    expect($out['macros'])->toBe([
        "/run GRM.RemovePlayerFromAltGroup(\"A-Realm\")\n/run GRM.RemovePlayerFromAltGroup(\"B-Realm\")",
    ]);
});

it('escapes embedded double quotes and backslashes in the name', function () {
    $out = UnlinkAltMacroBuilder::build(['Weird"-name\\Realm']);
    expect($out['macros'][0])->toBe('/run GRM.RemovePlayerFromAltGroup("Weird\\"-name\\\\Realm")');
});

it('skips empty / whitespace-only names', function () {
    $out = UnlinkAltMacroBuilder::build(['', '  ', 'Real-Name']);
    expect($out['macros'])->toBe(['/run GRM.RemovePlayerFromAltGroup("Real-Name")']);
});

it('handles diacritic names without overflowing', function () {
    $out = UnlinkAltMacroBuilder::build(['Ñýxx-Draenor', 'Andrômeda-ArgentDawn']);
    foreach ($out['macros'] as $macro) {
        expect(strlen($macro) <= 255)->toBeTrue();
    }
});
