<?php

namespace App\Support;

/**
 * Splits a list of full GRM character names ("Charname-Realm") into
 * one or more `/run GRM.SetMain(...)` macro strings, each capped at
 * WoW's 255-byte macro limit.
 *
 * GRM exposes `GRM.SetMain(playerName, timestamp?)` which sets the
 * passed character as the main of whichever alt group it already
 * belongs to. Calling it on a character that is already the main is
 * a no-op, and calling it on a character whose group has a different
 * main replaces the old main with the new one (no demote needed).
 *
 * Officer pastes the generated macro into WoW; the next GRM upload
 * reflects the change in the dashboard.
 */
class SetMainMacroBuilder
{
    public const MACRO_BYTE_LIMIT = LineMacroBuilder::MACRO_BYTE_LIMIT;

    /**
     * @param  iterable<string>  $names  Full names in "Char-Realm" form.
     * @return array{macros: list<string>, oversized: list<string>}
     */
    public static function build(iterable $names): array
    {
        $linesByName = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $linesByName[$name] = '/run GRM.SetMain("' . self::escape($name) . '")';
        }

        $packed = LineMacroBuilder::pack(array_values($linesByName));

        $oversizedNames = [];
        if ($packed['oversized'] !== []) {
            $lineToName = array_flip($linesByName);
            foreach ($packed['oversized'] as $line) {
                if (isset($lineToName[$line])) {
                    $oversizedNames[] = $lineToName[$line];
                }
            }
        }

        return ['macros' => $packed['macros'], 'oversized' => $oversizedNames];
    }

    /**
     * Escape a name for embedding in a Lua double-quoted string. WoW
     * character names allow apostrophes and accented characters but no
     * double quotes or backslashes, so this is mostly defensive.
     */
    private static function escape(string $name): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $name);
    }
}
