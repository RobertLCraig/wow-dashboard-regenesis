<?php

namespace App\Services\Blizzard;

use App\Models\MemberEquipmentSnapshot;

/**
 * Quick "is this character ready for raid invite" lens over a
 * MemberEquipmentSnapshot. Universal rules, no SimC profile needed
 * (unlike BisComparisonService which compares against a reference).
 *
 * Detects two classes of issue officers actually want to flag:
 *
 *   missing_enchants  Slots that should carry an enchant in current
 *                     retail and don't. Hardcoded slot list - any
 *                     "the rules changed for new expansion" updates
 *                     happen here, not at write-time.
 *   empty_sockets     A socket that exists on the item but has no
 *                     populated `item` payload, i.e. nothing
 *                     gemmed in.
 *
 * Returns slot type names ("CHEST", "FINGER_1") so the UI can render
 * tooltips or detail rows. Total is the sum, suitable for a single
 * "X issues" badge on the roster.
 */
class EquipmentAnalyzer
{
    /**
     * Slots that should carry an enchant in current retail
     * (The War Within / Midnight). Two ring slots are FINGER_1 and
     * FINGER_2 in the equipment payload. Weapons: MAIN_HAND for
     * casters/melee, OFF_HAND for dual wielders / shield holders -
     * we count only MAIN_HAND because the off-hand rule varies by
     * class and false positives are noisier than misses here.
     */
    private const ENCHANTABLE_SLOTS = [
        'CHEST',
        'WRIST',
        'LEGS',
        'FEET',
        'BACK',
        'FINGER_1',
        'FINGER_2',
        'MAIN_HAND',
    ];

    /**
     * @return array{
     *   missing_enchants: list<string>,
     *   empty_sockets: list<string>,
     *   total_issues: int,
     *   equipped_ilvl: ?int,
     *   pieces_count: int,
     * }
     */
    public function analyze(?MemberEquipmentSnapshot $snap): array
    {
        $empty = [
            'missing_enchants' => [],
            'empty_sockets' => [],
            'total_issues' => 0,
            'equipped_ilvl' => null,
            'pieces_count' => 0,
        ];
        if ($snap === null) {
            return $empty;
        }

        $pieces = $snap->pieces;
        if (! is_array($pieces) || $pieces === []) {
            $empty['equipped_ilvl'] = $snap->equipped_ilvl;
            return $empty;
        }

        $missingEnchants = [];
        $emptySockets = [];

        foreach ($pieces as $piece) {
            if (! is_array($piece)) {
                continue;
            }
            $slotType = $this->slotType($piece);
            if ($slotType === null) {
                continue;
            }

            if (in_array($slotType, self::ENCHANTABLE_SLOTS, true) && ! $this->hasEnchant($piece)) {
                $missingEnchants[] = $slotType;
            }

            foreach ($this->emptySocketsIn($piece) as $_) {
                $emptySockets[] = $slotType;
            }
        }

        return [
            'missing_enchants' => $missingEnchants,
            'empty_sockets' => $emptySockets,
            'total_issues' => count($missingEnchants) + count($emptySockets),
            'equipped_ilvl' => $snap->equipped_ilvl,
            'pieces_count' => count($pieces),
        ];
    }

    /**
     * @param  array<string,mixed>  $piece
     */
    private function slotType(array $piece): ?string
    {
        $type = $piece['slot']['type'] ?? null;
        return is_string($type) && $type !== '' ? $type : null;
    }

    /**
     * @param  array<string,mixed>  $piece
     */
    private function hasEnchant(array $piece): bool
    {
        $enchants = $piece['enchantments'] ?? null;
        if (! is_array($enchants) || $enchants === []) {
            return false;
        }
        // Blizzard sometimes returns a slot-level enchantment row with
        // no enchantment_id (the slot is "enchant-aware" but unenchanted).
        // Require at least one entry with a non-zero enchantment_id.
        foreach ($enchants as $row) {
            $id = is_array($row) ? ($row['enchantment_id'] ?? null) : null;
            if (is_numeric($id) && (int) $id > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Yields one entry per empty socket on the piece. A socket entry
     * with no populated `item` is unfilled.
     *
     * @param  array<string,mixed>  $piece
     * @return iterable<int, true>
     */
    private function emptySocketsIn(array $piece): iterable
    {
        $sockets = $piece['sockets'] ?? null;
        if (! is_array($sockets)) {
            return;
        }
        foreach ($sockets as $socket) {
            if (! is_array($socket)) {
                continue;
            }
            $item = $socket['item'] ?? null;
            if (! is_array($item) || empty($item['id'])) {
                yield true;
            }
        }
    }
}
