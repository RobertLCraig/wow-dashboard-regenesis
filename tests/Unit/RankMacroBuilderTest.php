<?php

use App\Support\RankMacroBuilder;

it('builds a /gpromote line per character, stripping realm', function () {
    $out = RankMacroBuilder::build(RankMacroBuilder::OP_PROMOTE, ['Sheday-Silvermoon']);
    expect($out['macros'])->toBe(['/gpromote Sheday']);
    expect($out['oversized'])->toBe([]);
});

it('builds a /gdemote line per character, stripping realm', function () {
    $out = RankMacroBuilder::build(RankMacroBuilder::OP_DEMOTE, ['Sheday-Silvermoon']);
    expect($out['macros'])->toBe(['/gdemote Sheday']);
});

it('joins multiple names with newlines until the 255-byte limit', function () {
    $out = RankMacroBuilder::build('promote', ['A-Realm', 'B-Realm']);
    expect($out['macros'])->toBe(["/gpromote A\n/gpromote B"]);
});

it('handles names already passed without realm', function () {
    $out = RankMacroBuilder::build('promote', ['Bare']);
    expect($out['macros'])->toBe(['/gpromote Bare']);
});

it('skips empty / whitespace-only names', function () {
    $out = RankMacroBuilder::build('demote', ['', '  ', 'Real-Name']);
    expect($out['macros'])->toBe(['/gdemote Real']);
});

it('rejects an unknown op', function () {
    expect(fn () => RankMacroBuilder::build('lolwhat', ['Sheday-Silvermoon']))
        ->toThrow(InvalidArgumentException::class);
});

it('preserves input order across macro splits', function () {
    // 30+ short names, enough to overflow into a second macro. Order
    // in the output should match input order.
    $names = array_map(fn ($i) => "Char{$i}-Realm", range(1, 40));
    $out = RankMacroBuilder::build('promote', $names);

    $allChars = [];
    foreach ($out['macros'] as $macro) {
        expect(strlen($macro) <= 255)->toBeTrue();
        foreach (explode("\n", $macro) as $line) {
            $allChars[] = substr($line, strlen('/gpromote '));
        }
    }
    expect($allChars)->toBe(array_map(fn ($i) => "Char{$i}", range(1, 40)));
});
