<?php

namespace App\Support;

/**
 * Splits a list of character names into one or more `/gremove` macro
 * strings, each capped at WoW's 255-byte macro limit.
 *
 * Output is what an officer pastes into the in-game macro UI. Each line
 * is `/gremove <Name>` separated by `\n`. WoW counts the total byte
 * length of the macro body (including newlines) against 255.
 *
 * Names are written to the macro in input order. Anyone whose
 * `/gremove <name>` line on its own would already exceed 255 bytes is
 * skipped and reported in the second return value (impossible in
 * practice for retail char names, which max out around 12 characters,
 * but defensive against weird inputs).
 */
class KickMacroBuilder
{
    public const MACRO_BYTE_LIMIT = 255;

    /**
     * @param  iterable<string>  $names  Character names without realm.
     * @return array{macros: list<string>, oversized: list<string>}
     */
    public static function build(iterable $names): array
    {
        $macros = [];
        $oversized = [];
        $current = '';

        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $line = "/gremove {$name}";

            // A single line that already overshoots 255 bytes can never
            // fit in any macro - report and skip rather than silently
            // truncating. strlen (not mb_strlen) because WoW char names
            // are ASCII-only.
            if (strlen($line) > self::MACRO_BYTE_LIMIT) {
                $oversized[] = $name;
                continue;
            }

            $candidate = $current === '' ? $line : $current . "\n" . $line;
            if (strlen($candidate) > self::MACRO_BYTE_LIMIT) {
                $macros[] = $current;
                $current = $line;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $macros[] = $current;
        }

        return ['macros' => $macros, 'oversized' => $oversized];
    }
}
