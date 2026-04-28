<?php

namespace App\Support;

/**
 * Builds `/run GRM.RemovePlayerFromAltGroup(...)` macro lines.
 *
 * GRM signature:
 *   GRM.RemovePlayerFromAltGroup(playerName, timestamp?, keepMainStatus?,
 *                                refreshUI?, syncChange?, leavingGuild?)
 *
 * Calling with just the playerName lets GRM pick `time()` for the
 * timestamp and treat the optional bools as falsy. That covers the
 * common case ("officer wants to break this alt link") without
 * exposing every flag in the UI.
 *
 * Officer pastes the macro in WoW; the next GRM ingest reflects the
 * unlinked state on the dashboard. Same kick-macro pattern: never
 * mutate GRM data from the dashboard directly.
 */
class UnlinkAltMacroBuilder
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
            $linesByName[$name] = '/run GRM.RemovePlayerFromAltGroup("' . self::escape($name) . '")';
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

    private static function escape(string $name): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $name);
    }
}
