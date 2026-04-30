<?php

namespace App\Services\Raiderio;

/**
 * Translate the realm portion of a GRM-style "Char-Realm" key into the
 * URL slug Raider.IO expects.
 *
 * GRM stores realm names with all spaces and apostrophes stripped (e.g.
 * "TwistingNether"). Raider.IO uses the slug form ("twisting-nether").
 * For single-word realms a simple lowercase works, but multi-word and
 * apostrophe-bearing realms need an explicit map (see config/raiderio.php).
 */
class RealmSlug
{
    /**
     * Pull the realm portion out of a "Char-Realm" GRM key. Char names
     * cannot contain hyphens in retail WoW, so splitting on the first
     * hyphen is unambiguous.
     */
    public static function realmFromMemberName(string $memberName): ?string
    {
        $pos = strpos($memberName, '-');
        if ($pos === false || $pos === strlen($memberName) - 1) {
            return null;
        }
        return substr($memberName, $pos + 1);
    }

    /**
     * Map a collapsed realm string ("TwistingNether") to a Raider.IO slug
     * ("twisting-nether"). Returns the configured default when input is
     * empty.
     *
     * Resolution order:
     *   1. Exact match in config('raiderio.realm_slugs')
     *   2. Strip apostrophes + transliterate accents + lowercase
     *
     * The collapsed form has lost its word boundaries, so the fallback
     * cannot recover hyphens; use the map for any multi-word realm. The
     * fallback only handles the easy single-word cases plus the
     * apostrophe / accent survivors GRM occasionally lets through
     * ("Drek'Thar" -> "drekthar", "Aggra(Português)" -> "aggraportugues").
     */
    public static function slugify(?string $collapsedRealm, ?string $default = null): string
    {
        if ($collapsedRealm === null || $collapsedRealm === '') {
            return $default ?? (string) config('raiderio.default_realm_slug', 'silvermoon');
        }
        $map = (array) config('raiderio.realm_slugs', []);
        if (isset($map[$collapsedRealm])) {
            return (string) $map[$collapsedRealm];
        }
        $ascii = self::transliterate($collapsedRealm);
        $stripped = str_replace(["'", "\u{2019}", '`', '(', ')'], '', $ascii);
        return strtolower($stripped);
    }

    /**
     * Slugify a canonical realm name with spaces and apostrophes
     * preserved (e.g. "Twisting Nether" -> "twisting-nether",
     * "Pozzo dell'Eternita" -> "pozzo-delleternita"). Used when we have
     * the realm via members.realm (backfilled from a previous RIO call)
     * and don't need to fall through the collapsed-name map.
     */
    public static function slugifyCanonical(?string $canonicalRealm): ?string
    {
        if ($canonicalRealm === null || $canonicalRealm === '') {
            return null;
        }
        // Transliterate first so accented characters (português, eternità)
        // become plain ASCII before the [a-z0-9] regex sees them and
        // turns them into stray hyphens. Without this, "Português"
        // becomes "portugu-s" instead of "portugues".
        $s = strtolower(self::transliterate($canonicalRealm));
        // Drop apostrophes outright (RIO does), then convert any
        // non-alphanumeric run (spaces, punctuation, etc.) to a single
        // hyphen, and trim hyphens at the edges.
        $s = str_replace(["'", "\u{2019}", '`'], '', $s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        return trim($s, '-');
    }

    /**
     * Best-effort UTF-8 to ASCII transliteration. Prefers PHP's Intl
     * Transliterator (ICU-backed, identical output on Windows + Linux);
     * falls back to iconv for hosts without the intl extension. Both
     * convert: ê -> e, à -> a, ñ -> n, ç -> c, etc.
     *
     * iconv was tried first but Windows libiconv emits "^e" for "ê"
     * (the caret as a separator) while glibc on Hostinger emits "e";
     * the divergence broke the test suite cross-platform. ICU does the
     * sensible thing on both.
     */
    private static function transliterate(string $s): string
    {
        if (class_exists(\Transliterator::class)) {
            static $tr = null;
            if ($tr === null) {
                $tr = \Transliterator::create('Any-Latin; Latin-ASCII');
            }
            if ($tr instanceof \Transliterator) {
                $converted = $tr->transliterate($s);
                if (is_string($converted) && $converted !== '') {
                    return $converted;
                }
            }
        }
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        return is_string($converted) && $converted !== '' ? $converted : $s;
    }
}
