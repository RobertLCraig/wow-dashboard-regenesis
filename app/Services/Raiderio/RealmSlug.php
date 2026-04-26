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
     *   2. Lowercase fallback (correct for single-word realms)
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
        return strtolower($collapsedRealm);
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
        $s = mb_strtolower($canonicalRealm);
        // Drop apostrophes outright (RIO does), then convert any
        // non-alphanumeric run (spaces, punctuation, etc.) to a single
        // hyphen, and trim hyphens at the edges.
        $s = str_replace(["'", '’'], '', $s);
        $s = preg_replace('/[^a-z0-9]+/u', '-', $s) ?? '';
        return trim($s, '-');
    }
}
