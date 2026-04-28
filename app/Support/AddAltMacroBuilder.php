<?php

namespace App\Support;

/**
 * Builds a `/run GRM.AddAlt(...)` macro string for one source-target
 * pair. Both names must be full "Char-Realm" form; GRM resolves them
 * against its own database.
 *
 * GRM signature:
 *   GRM.AddAlt(playerName, secondPlayerName, timestamp?)
 *
 * Calling with just the two names lets GRM pick `time()` for the
 * timestamp. AddAlt handles all four combinations (neither linked,
 * one linked, both linked) so the dashboard doesn't need to inspect
 * existing groups before generating the macro.
 */
class AddAltMacroBuilder
{
    public const MACRO_BYTE_LIMIT = LineMacroBuilder::MACRO_BYTE_LIMIT;

    /**
     * @return array{macro: ?string, oversized: bool, error: ?string}
     */
    public static function build(string $sourceName, string $targetName): array
    {
        $sourceName = trim($sourceName);
        $targetName = trim($targetName);
        if ($sourceName === '' || $targetName === '') {
            return ['macro' => null, 'oversized' => false, 'error' => 'both names required'];
        }
        if ($sourceName === $targetName) {
            return ['macro' => null, 'oversized' => false, 'error' => 'cannot link a character to itself'];
        }

        $line = sprintf(
            '/run GRM.AddAlt("%s","%s")',
            self::escape($sourceName),
            self::escape($targetName),
        );

        if (strlen($line) > self::MACRO_BYTE_LIMIT) {
            return ['macro' => null, 'oversized' => true, 'error' => 'macro line exceeds 255 bytes'];
        }

        return ['macro' => $line, 'oversized' => false, 'error' => null];
    }

    private static function escape(string $name): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $name);
    }
}
