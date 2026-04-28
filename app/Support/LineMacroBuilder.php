<?php

namespace App\Support;

/**
 * Packs a list of macro lines (one operation each) into the smallest
 * number of 255-byte WoW macro bodies, preserving order. Used by every
 * "generate a macro the officer pastes into chat" flow: kick + alts,
 * set main, edit GRM custom note, etc.
 *
 * WoW counts the total byte length of a macro body, including the
 * separating newlines, against 255. Anything that would overshoot
 * gets split into the next macro. Any single line that already
 * exceeds 255 bytes can never fit and is reported in `oversized`
 * instead of being silently truncated.
 */
class LineMacroBuilder
{
    public const MACRO_BYTE_LIMIT = 255;

    /**
     * @param  iterable<string>  $lines  Pre-formatted macro lines (no trailing \n).
     * @return array{macros: list<string>, oversized: list<string>}
     */
    public static function pack(iterable $lines): array
    {
        $macros = [];
        $oversized = [];
        $current = '';

        foreach ($lines as $line) {
            $line = (string) $line;
            if ($line === '') {
                continue;
            }
            if (strlen($line) > self::MACRO_BYTE_LIMIT) {
                $oversized[] = $line;
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
