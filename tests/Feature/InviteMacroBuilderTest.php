<?php

use App\Support\InviteMacroBuilder;

it('builds a single macro for a small list', function () {
    $result = InviteMacroBuilder::build(['Sheday', 'Tute', 'Aakervik']);
    expect($result['macros'])->toBe(["/invite Sheday\n/invite Tute\n/invite Aakervik"]);
    expect($result['oversized'])->toBe([]);
});

it('splits across multiple macros at the 255-byte boundary', function () {
    // Generate enough names that one macro overflows.
    $names = array_map(fn ($i) => 'Player' . str_pad((string) $i, 3, '0', STR_PAD_LEFT), range(1, 30));
    $result = InviteMacroBuilder::build($names);

    foreach ($result['macros'] as $macro) {
        expect(strlen($macro))->toBeLessThanOrEqual(InviteMacroBuilder::MACRO_BYTE_LIMIT);
    }
    expect(count($result['macros']))->toBeGreaterThan(1);
});

it('preserves input order across macros', function () {
    $names = array_map(fn ($i) => 'PlayerNumber' . $i, range(1, 30));
    $result = InviteMacroBuilder::build($names);
    $joined = implode("\n", $result['macros']);
    $lastPos = -1;
    foreach ($names as $n) {
        $pos = strpos($joined, "/invite {$n}");
        expect($pos)->not->toBeFalse();
        expect($pos)->toBeGreaterThan($lastPos);
        $lastPos = $pos;
    }
});

it('skips a single name whose /invite line exceeds 255 bytes on its own', function () {
    $monster = str_repeat('A', 260);
    $result = InviteMacroBuilder::build(['Sheday', $monster, 'Aakervik']);

    expect($result['macros'])->toBe(["/invite Sheday\n/invite Aakervik"]);
    expect($result['oversized'])->toBe([$monster]);
});

it('drops empty / whitespace names without flagging them oversized', function () {
    $result = InviteMacroBuilder::build(['Sheday', '', '   ', 'Aakervik']);
    expect($result['macros'])->toBe(["/invite Sheday\n/invite Aakervik"]);
    expect($result['oversized'])->toBe([]);
});

it('cleanName strips parenthetical alts and slash/comma fragments', function () {
    expect(InviteMacroBuilder::cleanName('Knicksier'))->toBe('Knicksier');
    expect(InviteMacroBuilder::cleanName('Rohan,drawmedomes(Larasala)'))->toBe('Rohan');
    expect(InviteMacroBuilder::cleanName('Arianne/Allie'))->toBe('Arianne');
    expect(InviteMacroBuilder::cleanName('Char (alt: Other)'))->toBe('Char');
    expect(InviteMacroBuilder::cleanName('  Sheday  '))->toBe('Sheday');
});

it('cleanName returns null for empty / whitespace / null input', function () {
    expect(InviteMacroBuilder::cleanName(null))->toBeNull();
    expect(InviteMacroBuilder::cleanName(''))->toBeNull();
    expect(InviteMacroBuilder::cleanName('   '))->toBeNull();
    expect(InviteMacroBuilder::cleanName('()'))->toBeNull();
});
