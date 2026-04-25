<?php

use App\Services\Grm\LuaTableParser;

it('parses an empty table', function () {
    expect((new LuaTableParser)->parse('FOO = {}'))
        ->toBe(['FOO' => []]);
});

it('parses primitive values', function () {
    $src = <<<'LUA'
        S = "hello"
        N = 42
        F = -3.14
        T = true
        L = false
        Z = nil
    LUA;
    expect((new LuaTableParser)->parse($src))->toMatchArray([
        'S' => 'hello',
        'N' => 42,
        'F' => -3.14,
        'T' => true,
        'L' => false,
        'Z' => null,
    ]);
});

it('preserves positional ordering and 1-based indices like Lua', function () {
    $parser = new LuaTableParser;
    $out = $parser->parse('ARR = { "a", "b", "c" }');
    expect($out['ARR'])->toBe([1 => 'a', 2 => 'b', 3 => 'c']);
});

it('parses string-keyed entries with bracket-quote syntax', function () {
    $src = '
        T = {
            ["name"] = "Totem",
            ["level"] = 80,
            ["online"] = false,
        }
    ';
    expect((new LuaTableParser)->parse($src)['T'])->toBe([
        'name' => 'Totem',
        'level' => 80,
        'online' => false,
    ]);
});

it('mixes positional and keyed entries in the same table', function () {
    $src = 'T = { "first", ["k"] = "v", "second" }';
    expect((new LuaTableParser)->parse($src)['T'])->toBe([
        1 => 'first',
        'k' => 'v',
        2 => 'second',
    ]);
});

it('handles nested tables with trailing commas', function () {
    $src = '
        OUTER = {
            ["inner"] = {
                ["deep"] = { 1, 2, 3, },
                ["leaf"] = "ok",
            },
        }
    ';
    expect((new LuaTableParser)->parse($src)['OUTER'])->toBe([
        'inner' => [
            'deep' => [1 => 1, 2 => 2, 3 => 3],
            'leaf' => 'ok',
        ],
    ]);
});

it('decodes string escape sequences including numeric \\ddd', function () {
    $src = 'X = "line1\\nline2\\t\\116ab"';
    expect((new LuaTableParser)->parse($src)['X'])->toBe("line1\nline2\ttab");
});

it('parses numeric keys in bracket form', function () {
    $src = 'T = { ["43"] = "alts", ["236"] = "another" }';
    expect((new LuaTableParser)->parse($src)['T'])->toBe([
        '43' => 'alts',
        '236' => 'another',
    ]);
});

it('skips line comments', function () {
    $src = "-- top comment\nT = { -- inside\n  1, -- trailing\n  2,\n}";
    expect((new LuaTableParser)->parse($src)['T'])->toBe([1 => 1, 2 => 2]);
});

it('honors the $only filter and skips other globals', function () {
    $src = '
        WANTED = { ["a"] = 1 }
        IGNORED = { ["b"] = 2, ["c"] = { "d", "e", "f" } }
        ALSO_WANTED = { 7, 8 }
    ';
    expect((new LuaTableParser)->parse($src, ['WANTED', 'ALSO_WANTED']))->toBe([
        'WANTED' => ['a' => 1],
        'ALSO_WANTED' => [1 => 7, 2 => 8],
    ]);
});

it('throws on malformed input rather than silently dropping data', function () {
    expect(fn () => (new LuaTableParser)->parse('T = { "unterminated'))
        ->toThrow(RuntimeException::class);
});

it('handles a realistic GRM-shaped fragment with embedded color codes', function () {
    // Mirrors the shape we observed in
    // GRM_LogReport_Save["Regenesis-Silvermoon"][N] - integer type code
    // followed by a rendered string with WoW colour codes left in.
    $src = <<<'LUA'
        GRM_LogReport_Save = {
            ["Regenesis-Silvermoon"] = {
                {
                    1,
                    "18 Feb '26 08:15pm : |cffffffffMeissa|r PROMOTED |cffa330c9Panch|r from Member to Heroic Raider",
                    true,
                    "|cffffffffMeissa|r",
                    "|cffa330c9Panch|r",
                    "Member",
                    "Heroic Raider",
                    {
                        18,
                        2,
                        2026,
                        20,
                        15,
                    },
                },
            },
        }
        LUA;

    $out = (new LuaTableParser)->parse($src);
    expect($out['GRM_LogReport_Save']['Regenesis-Silvermoon'])->toHaveCount(1);

    $row = $out['GRM_LogReport_Save']['Regenesis-Silvermoon'][1];
    expect($row[1])->toBe(1);
    expect($row[2])->toContain('PROMOTED')->toContain('|cffffffff');
    expect($row[3])->toBeTrue();
    expect($row[8])->toBe([1 => 18, 2 => 2, 3 => 2026, 4 => 20, 5 => 15]);
});
