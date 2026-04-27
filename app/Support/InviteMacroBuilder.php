<?php

namespace App\Support;

/**
 * Splits a list of character names into one or more `/invite` macro
 * strings, each capped at WoW's 255-byte macro limit. Mirrors the
 * shape of KickMacroBuilder so both surfaces feel consistent.
 *
 * Output is what a raid leader pastes into the in-game macro UI: each
 * line is `/invite <Name>` separated by `\n`. WoW counts the total byte
 * length of the macro body (including newlines) against 255.
 *
 * Names are written in input order. Anyone whose `/invite <name>` line
 * on its own would already exceed 255 bytes is skipped and reported
 * back so the widget can surface the issue (rarely fires in practice -
 * retail char names max out around 12 characters).
 */
class InviteMacroBuilder
{
    public const MACRO_BYTE_LIMIT = 255;

    /**
     * @param  iterable<string>  $names
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
            $line = "/invite {$name}";

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

    /**
     * Best-effort cleanup of a Raid-Helper signup name into something
     * usable as a WoW char name. Raid-Helper stores Discord display
     * names verbatim, so we see things like "Rohan,drawmedomes(Larasala)"
     * (alt list with parens), "Arianne/Allie" (split nickname), or
     * "Knicksier" (already clean).
     *
     * Heuristics: drop anything in parentheses, then take the first
     * token before a comma / slash / whitespace. Returns null when the
     * cleanup leaves nothing useful so callers can drop the row.
     */
    public static function cleanName(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        // Drop "(anything)" sections - usually parenthetical alt lists.
        $stripped = preg_replace('/\s*\([^)]*\)\s*/u', ' ', $raw) ?? $raw;
        // Take the first token before a comma, slash, or whitespace.
        $token = preg_split('/[\s,\/]+/u', trim($stripped), 2)[0] ?? '';
        $token = trim($token);
        return $token === '' ? null : $token;
    }
}
