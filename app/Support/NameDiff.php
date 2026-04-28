<?php

namespace App\Support;

/**
 * Annotate a character name against its alt-group siblings so the
 * differing letters can be visually highlighted in the roster.
 *
 * Several guild members run alt sets where the names differ only by
 * diacritics (Ñýxx vs Ñyxx vs Nýxx, Andrômeda vs Andromedà). At small
 * font sizes the marks are easy to miss and the reader assumes the
 * wrong row. The annotation marks each character of the name with
 * isDifferent=true when at least one sibling whose ASCII-folded name
 * is identical has a different character at that position.
 *
 * Falling back to a plain pass-through when no sibling collides keeps
 * the common case (no near-twins) lean.
 */
class NameDiff
{
    /**
     * @param  iterable<string>  $siblings  Other names in the same alt group.
     * @return list<array{0:string, 1:bool}>  [char, isDifferent] per grapheme.
     */
    public static function annotate(string $name, iterable $siblings): array
    {
        $chars = mb_str_split($name);

        $fold = self::asciiFold($name);
        $collidingSiblings = [];
        foreach ($siblings as $sibling) {
            if ($sibling === $name) {
                continue;
            }
            if (self::asciiFold($sibling) === $fold) {
                $collidingSiblings[] = mb_str_split($sibling);
            }
        }

        if ($collidingSiblings === []) {
            return array_map(static fn (string $c) => [$c, false], $chars);
        }

        $out = [];
        foreach ($chars as $i => $char) {
            $diff = false;
            foreach ($collidingSiblings as $sib) {
                if (($sib[$i] ?? null) !== $char) {
                    $diff = true;
                    break;
                }
            }
            $out[] = [$char, $diff];
        }
        return $out;
    }

    /**
     * Strip diacritics + lowercase. Uses Intl's Transliterator if
     * present (correct for any Unicode), iconv as a fallback.
     */
    private static function asciiFold(string $s): string
    {
        if (class_exists(\Transliterator::class)) {
            $t = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
            if ($t !== null) {
                $folded = $t->transliterate($s);
                if ($folded !== false) {
                    return mb_strtolower($folded);
                }
            }
        }
        $iconv = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        return mb_strtolower($iconv !== false ? $iconv : $s);
    }
}
