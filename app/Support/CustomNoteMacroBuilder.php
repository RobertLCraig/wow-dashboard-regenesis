<?php

namespace App\Support;

/**
 * Builds a `/run GRM_API.EditCustomNote(...)` macro string for one
 * character. GRM caps custom notes at 150 chars on its side, so the
 * resulting line always fits well under WoW's 255-byte macro limit
 * for any realistic guild member name.
 *
 * GRM's API signature:
 *   GRM_API.EditCustomNote(player_name, new_note, replace_existing, skip_log_entry)
 *
 * - replace_existing=true overwrites the current custom note.
 * - replace_existing=false (or nil) appends with a newline separator.
 * - skip_log_entry=false leaves a GRM log entry, which we want for
 *   parity with edits made in-game.
 *
 * Custom note != WoW Public/Officer note: this is GRM's own field
 * and never touches the Blizzard slots.
 */
class CustomNoteMacroBuilder
{
    public const MAX_NOTE_LENGTH = 150;
    public const MACRO_BYTE_LIMIT = LineMacroBuilder::MACRO_BYTE_LIMIT;

    /**
     * @return array{macro: ?string, oversized: bool, error: ?string}
     */
    public static function build(string $name, string $note, bool $replace): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['macro' => null, 'oversized' => false, 'error' => 'name required'];
        }
        if ($note === '') {
            // GRM_API.EditCustomNote returns false on an empty note. Surface
            // that early so the caller doesn't generate a no-op macro.
            return ['macro' => null, 'oversized' => false, 'error' => 'note must not be empty'];
        }
        if (mb_strlen($note) > self::MAX_NOTE_LENGTH) {
            return ['macro' => null, 'oversized' => false, 'error' => 'note exceeds GRM 150-char limit'];
        }

        $line = sprintf(
            '/run GRM_API.EditCustomNote("%s","%s",%s,false)',
            self::escapeLua($name),
            self::escapeLua($note),
            $replace ? 'true' : 'false',
        );

        if (strlen($line) > self::MACRO_BYTE_LIMIT) {
            // Shouldn't happen given the 150-char note cap + realistic
            // names, but defensive in case of multi-byte note text.
            return ['macro' => null, 'oversized' => true, 'error' => 'macro line exceeds 255 bytes'];
        }

        return ['macro' => $line, 'oversized' => false, 'error' => null];
    }

    /**
     * Escape a string for embedding in a Lua double-quoted literal.
     * Newlines are encoded as `\n` so multi-line notes round-trip
     * through GRM's append-with-newline behaviour cleanly.
     */
    private static function escapeLua(string $s): string
    {
        return str_replace(
            ['\\', '"', "\r\n", "\n", "\r"],
            ['\\\\', '\\"', '\\n', '\\n', '\\n'],
            $s,
        );
    }
}
