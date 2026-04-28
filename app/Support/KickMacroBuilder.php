<?php

namespace App\Support;

/**
 * Splits a list of character names into one or more `/gremove` macro
 * strings, each capped at WoW's 255-byte macro limit.
 *
 * Output is what an officer pastes into the in-game macro UI. Each
 * line is `/gremove <Name>` separated by `\n`. Names whose
 * `/gremove <name>` line on its own would already exceed 255 bytes
 * are skipped and reported in the second return value (impossible in
 * practice for retail char names, which max out around 12 characters,
 * but defensive against weird inputs).
 *
 * Packing logic lives in {@see LineMacroBuilder} so the same byte-
 * limit handling is shared across kick / set-main / set-note flows.
 */
class KickMacroBuilder
{
    public const MACRO_BYTE_LIMIT = LineMacroBuilder::MACRO_BYTE_LIMIT;

    /**
     * @param  iterable<string>  $names  Character names without realm.
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
            $linesByName[$name] = "/gremove {$name}";
        }

        $packed = LineMacroBuilder::pack(array_values($linesByName));

        // LineMacroBuilder returns oversized *lines*; this builder's
        // contract is to return oversized *names*. Map back via the
        // name->line map we built above.
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
}
