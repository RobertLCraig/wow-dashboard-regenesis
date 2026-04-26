<?php

use App\Support\KickMacroBuilder;

it('returns empty when given no names', function () {
    $r = KickMacroBuilder::build([]);
    expect($r)->toBe(['macros' => [], 'oversized' => []]);
});

it('puts a single name into one macro', function () {
    $r = KickMacroBuilder::build(['Sheday']);
    expect($r['macros'])->toBe(['/gremove Sheday']);
    expect($r['oversized'])->toBe([]);
});

it('joins multiple short names into one macro with newline separators', function () {
    $r = KickMacroBuilder::build(['Sheday', 'Tute', 'Argus']);
    expect($r['macros'])->toBe(["/gremove Sheday\n/gremove Tute\n/gremove Argus"]);
});

it('splits into a second macro when the cumulative length would exceed 255 bytes', function () {
    // 12-char name -> 21-char line (`/gremove ` = 9 + 12). With newline
    // separators that is 22 bytes per added line. 11 lines = 21 + 10*22
    // = 241 (fits). 12 lines would be 263 (overflow).
    $names = array_map(fn ($i) => 'Charabcdefgh' . $i, range(0, 11));   // 12 names

    $r = KickMacroBuilder::build($names);

    expect(count($r['macros']))->toBe(2);
    foreach ($r['macros'] as $macro) {
        expect(strlen($macro))->toBeLessThanOrEqual(255);
    }
    // Every name made it in across both macros.
    $joined = implode("\n", $r['macros']);
    foreach ($names as $name) {
        expect($joined)->toContain('/gremove ' . $name);
    }
});

it('reports a name whose own /gremove line already exceeds 255 bytes as oversized and skips it', function () {
    // Synthetic: 250-char name pushes the line past the limit.
    $oversize = str_repeat('A', 250);

    $r = KickMacroBuilder::build(['Sheday', $oversize, 'Tute']);

    expect($r['oversized'])->toBe([$oversize]);
    expect($r['macros'])->toBe(["/gremove Sheday\n/gremove Tute"]);
});

it('trims whitespace and ignores blank entries', function () {
    $r = KickMacroBuilder::build(['  Sheday  ', '', '   ', 'Tute']);
    expect($r['macros'])->toBe(["/gremove Sheday\n/gremove Tute"]);
});

it('preserves input order across macro splits', function () {
    $names = array_map(fn ($i) => 'Char' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), range(1, 30));
    $r = KickMacroBuilder::build($names);

    $joined = implode("\n", $r['macros']);
    $lines = explode("\n", $joined);
    $namesInOrder = array_map(fn ($l) => substr($l, strlen('/gremove ')), $lines);
    expect($namesInOrder)->toBe($names);
});
