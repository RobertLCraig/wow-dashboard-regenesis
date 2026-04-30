<?php

namespace App\Services\Raiderio;

/**
 * Translate the realm portion of a GRM-style "Char-Realm" key into the
 * URL slug Raider.IO expects.
 *
 * GRM stores the realm portion of a member name with spaces stripped
 * (e.g. "TwistingNether") but apostrophes / parens / accents are
 * sometimes preserved verbatim, so the input here can be in any of
 * several shapes. Raider.IO's slug form is documented as ASCII-with-
 * hyphens (e.g. "twisting-nether") but in practice RIO PRESERVES
 * unicode characters in slugs - the slug for Aggra (Português) is
 * "aggra-português" (with the actual ê), not "aggra-portugues".
 * Transliterating accented chars to ASCII before slugifying produced
 * 400s in production and was reverted.
 *
 * Multi-word realms whose original spaces have been collapsed away by
 * GRM ("TwistingNether") cannot be recovered from the collapsed form -
 * use the explicit map in config/raiderio.php for those.
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
     *   2. Lowercase + apostrophe/paren cleanup, unicode preserved
     *
     * The collapsed form has lost its word boundaries, so the fallback
     * cannot recover hyphens between words. Use the map for any
     * multi-word realm. The fallback handles single-word realms and
     * the apostrophe / paren survivors GRM occasionally lets through
     * (e.g. "Drek'Thar" -> "drekthar", "Aggra(Português)" -> "aggra-português").
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
        // Apostrophes + parens become hyphens. RIO's canonical slug for
        // "Pozzo dell'Eternità" is "pozzo-dell-eternità" (apos becomes
        // hyphen between dell + eternità). RIO is forgiving on the
        // collapsed alternatives ("drekthar" and "drek-thar" both 200),
        // so apos-as-hyphen is the consistent rule.
        $lowered = mb_strtolower($collapsedRealm);
        $hyphenated = preg_replace("/[' \u{2019}` ()]+/u", '-', $lowered) ?? $lowered;
        $collapsed = preg_replace('/-+/', '-', $hyphenated) ?? $hyphenated;
        return trim($collapsed, '-');
    }

    /**
     * Slugify a canonical realm name with spaces and apostrophes
     * preserved (e.g. "Twisting Nether" -> "twisting-nether",
     * "Pozzo dell'Eternità" -> "pozzo-dell-eternità"). Used when we have
     * the realm via members.realm (backfilled from a previous RIO call)
     * and don't need to fall through the collapsed-name map.
     *
     * Unicode is preserved: RIO accepts "aggra-português" but rejects
     * "aggra-portugues" with a 400. Don't transliterate.
     */
    public static function slugifyCanonical(?string $canonicalRealm): ?string
    {
        if ($canonicalRealm === null || $canonicalRealm === '') {
            return null;
        }
        $s = mb_strtolower($canonicalRealm);
        // Replace apostrophes + whitespace + parens with hyphens. Use
        // \p{L} / \p{N} to keep accented letters intact - RIO preserves
        // them in slugs ("aggra-português", not "aggra-portugues").
        $s = preg_replace('/[^\p{L}\p{N}]+/u', '-', $s) ?? '';
        return trim($s, '-');
    }
}
