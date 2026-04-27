<?php

namespace App\Support;

/**
 * Tiny helper for building Wowhead item URLs and the data-wowhead
 * attribute payload their power.js consumes. The tooltip JS is loaded
 * via a CDN tag in the layout - this class just produces values that
 * pair with it.
 *
 * URL form:   https://www.wowhead.com/item={id}[?bonus={a}:{b}:...]
 * Attr form:  item={id}[&bonus=a:b][&gems=x:y][&ench=z]
 *
 * No EU-specific domain handling; the tooltip script handles
 * localisation regardless of which subdomain the link targets.
 */
class Wowhead
{
    /**
     * Public-facing item URL. Bonuses are colon-joined onto a `?bonus=`
     * query parameter so the tooltip resolves to the correct ilvl
     * variant (raid drops have many bonus permutations).
     *
     * @param  list<int>  $bonusIds
     */
    public static function url(int $itemId, array $bonusIds = []): string
    {
        $url = "https://www.wowhead.com/item={$itemId}";
        if ($bonusIds !== []) {
            $url .= '?bonus=' . implode(':', $bonusIds);
        }
        return $url;
    }

    /**
     * Value for the `data-wowhead` attribute. power.js reads this on
     * any element with the attribute and renders an inline tooltip.
     *
     * @param  list<int>  $bonusIds
     * @param  list<int>  $gemIds
     */
    public static function dataAttr(
        int $itemId,
        array $bonusIds = [],
        array $gemIds = [],
        ?int $enchantId = null,
    ): string {
        $parts = ["item={$itemId}"];
        if ($bonusIds !== []) {
            $parts[] = 'bonus=' . implode(':', $bonusIds);
        }
        if ($gemIds !== []) {
            $parts[] = 'gems=' . implode(':', $gemIds);
        }
        if ($enchantId !== null) {
            $parts[] = "ench={$enchantId}";
        }
        return implode('&', $parts);
    }

    /**
     * Pretty-print a SimC-style item slug ("relentless_riders_crown")
     * back to title case ("Relentless Riders Crown"). Falls through
     * cleanly on null / empty input.
     */
    public static function formatItemName(?string $slug): ?string
    {
        if ($slug === null || trim($slug) === '') {
            return null;
        }
        return ucwords(str_replace('_', ' ', $slug));
    }
}
