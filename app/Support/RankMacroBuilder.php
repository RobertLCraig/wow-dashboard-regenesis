<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Splits a list of character names into one or more `/gpromote` or
 * `/gdemote` macro strings, each capped at WoW's 255-byte macro limit.
 *
 * Both Blizzard slash commands take the character name without realm
 * suffix (`/gpromote Sheday`, not `/gpromote Sheday-Silvermoon`), same
 * convention as `/gremove` in {@see KickMacroBuilder}.
 *
 * Operations are mutually exclusive per build: officer either promotes
 * a batch or demotes a batch, never both in the same macro.
 */
class RankMacroBuilder
{
    public const OP_PROMOTE = 'promote';
    public const OP_DEMOTE = 'demote';

    public const MACRO_BYTE_LIMIT = LineMacroBuilder::MACRO_BYTE_LIMIT;

    /**
     * @param  iterable<string>  $names  Character names with or without "-Realm" suffix.
     * @return array{macros: list<string>, oversized: list<string>}
     */
    public static function build(string $op, iterable $names): array
    {
        $command = match ($op) {
            self::OP_PROMOTE => '/gpromote',
            self::OP_DEMOTE => '/gdemote',
            default => throw new InvalidArgumentException("Unknown rank op: {$op}"),
        };

        $linesByChar = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $charName = explode('-', $name, 2)[0];
            $linesByChar[$charName] = "{$command} {$charName}";
        }

        $packed = LineMacroBuilder::pack(array_values($linesByChar));

        $oversizedNames = [];
        if ($packed['oversized'] !== []) {
            $lineToName = array_flip($linesByChar);
            foreach ($packed['oversized'] as $line) {
                if (isset($lineToName[$line])) {
                    $oversizedNames[] = $lineToName[$line];
                }
            }
        }

        return ['macros' => $packed['macros'], 'oversized' => $oversizedNames];
    }
}
