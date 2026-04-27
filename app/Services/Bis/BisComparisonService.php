<?php

namespace App\Services\Bis;

use App\Models\BisProfile;
use App\Models\Member;
use App\Models\MemberSnapshot;
use App\Models\Snapshot;

/**
 * Compares a member's actual gear against their class+spec BiS profile.
 *
 * Source of actual gear: the most recent Raider.IO snapshot's raw_json,
 * because that's where we get per-slot enchants and gems. Wowaudit also
 * carries this and could be a fallback (Phase 3b); Blizzard's profile
 * summary doesn't, so it's not useful here even though it wins on ilvl.
 *
 * Source of BiS data: bis_profiles, populated from SimulationCraft
 * profile files. We match on (class, spec, hero_talent IS NULL) - the
 * default profile per spec. Hero-talent-aware matching is a follow-up
 * once we can read the active hero talent from the same raw payload.
 *
 * Returns null when any input is missing (no RIO data, can't determine
 * spec, no matching BiS profile). The character page renders nothing
 * in that case.
 */
class BisComparisonService
{
    /**
     * Map our internal class column (uppercase, no separator from GRM)
     * to the snake_case form bis_profiles uses.
     */
    private const CLASS_NORMALISE = [
        'DEATHKNIGHT' => 'death_knight',
        'DEMONHUNTER' => 'demon_hunter',
        'DRUID'       => 'druid',
        'EVOKER'      => 'evoker',
        'HUNTER'      => 'hunter',
        'MAGE'        => 'mage',
        'MONK'        => 'monk',
        'PALADIN'     => 'paladin',
        'PRIEST'      => 'priest',
        'ROGUE'       => 'rogue',
        'SHAMAN'      => 'shaman',
        'WARLOCK'     => 'warlock',
        'WARRIOR'     => 'warrior',
    ];

    /**
     * Raider.IO uses singular for some slots and a collapsed weapon
     * key; SimC uses plural / underscore. Map RIO -> SimC so a single
     * slot key drives the comparison rows.
     */
    private const RIO_TO_SIMC_SLOT = [
        'head'     => 'head',
        'neck'     => 'neck',
        'shoulder' => 'shoulders',
        'back'     => 'back',
        'chest'    => 'chest',
        'wrist'    => 'wrists',
        'hands'    => 'hands',
        'waist'    => 'waist',
        'legs'     => 'legs',
        'feet'     => 'feet',
        'finger1'  => 'finger1',
        'finger2'  => 'finger2',
        'trinket1' => 'trinket1',
        'trinket2' => 'trinket2',
        'mainhand' => 'main_hand',
        'offhand'  => 'off_hand',
    ];

    private const ALL_SLOTS = [
        'head', 'neck', 'shoulders', 'back', 'chest', 'wrists',
        'hands', 'waist', 'legs', 'feet',
        'finger1', 'finger2', 'trinket1', 'trinket2',
        'main_hand', 'off_hand',
    ];

    /**
     * @return array{
     *   class:string,
     *   spec:string,
     *   profile_name:string,
     *   profile_gear_ilvl:?float,
     *   source:string,
     *   source_captured_at:?\Carbon\CarbonInterface,
     *   slots:array<string, array<string,mixed>>,
     *   consumables:array<string,string>,
     * }|null
     */
    public function compareForMember(Member $member): ?array
    {
        $rioSnap = MemberSnapshot::query()
            ->whereHas('snapshot', fn ($q) => $q->where('source', Snapshot::SOURCE_RAIDERIO))
            ->where('member_id', $member->id)
            ->with('snapshot:id,source,captured_at')
            ->orderByDesc('id')
            ->first();
        if ($rioSnap === null) {
            return null;
        }

        $raw = $this->rawArray($rioSnap);
        if ($raw === null) {
            return null;
        }

        $profile = $this->resolveProfileFor($member, $raw);
        if ($profile === null) {
            return null;
        }

        return $this->compareWithData($member, $raw, $profile, $rioSnap->snapshot?->captured_at);
    }

    /**
     * Resolve the best-matching BiS profile for a member based on their
     * class and active spec. When multiple profiles exist for the same
     * class+spec (one default + N hero-talent variants), score each by
     * item-id overlap with the actual RIO gear and pick the highest.
     * Tie goes to the default (hero_talent IS NULL) profile.
     *
     * Bulk callers should prefer pre-loading the candidates and passing
     * them in so we don't N+1 across the whole roster.
     *
     * @param  array<string,mixed>  $rioRaw
     * @param  \Illuminate\Support\Collection<int,BisProfile>|null  $candidates
     */
    public function resolveProfileFor(Member $member, array $rioRaw, ?\Illuminate\Support\Collection $candidates = null): ?BisProfile
    {
        if ($candidates === null) {
            $class = $this->classKey($member);
            $spec = $this->normaliseSpec($rioRaw['active_spec_name'] ?? null);
            if ($class === null || $spec === null) {
                return null;
            }
            $candidates = BisProfile::query()
                ->where('class', $class)
                ->where('spec', $spec)
                ->get();
        }
        return $this->pickBestProfile($candidates, $rioRaw);
    }

    /**
     * Score each candidate profile by exact item-id overlap with the
     * player's actual gear. Highest score wins; ties prefer the default
     * profile so a player with no clearly-aligned variant still gets a
     * sensible fallback.
     *
     * @param  \Illuminate\Support\Collection<int,BisProfile>  $candidates
     * @param  array<string,mixed>  $rioRaw
     */
    public function pickBestProfile(\Illuminate\Support\Collection $candidates, array $rioRaw): ?BisProfile
    {
        if ($candidates->isEmpty()) {
            return null;
        }
        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        $default = $candidates->first(fn (BisProfile $p) => $p->hero_talent === null);
        $actualGear = $this->extractActualGear($rioRaw);
        if ($actualGear === []) {
            // No gear to score against; prefer the default profile.
            return $default ?? $candidates->first();
        }

        $bestScore = -1;
        $best = $default ?? $candidates->first();
        foreach ($candidates as $candidate) {
            $bisGear = is_array($candidate->parsed_data['gear'] ?? null) ? $candidate->parsed_data['gear'] : [];
            $score = 0;
            foreach ($actualGear as $slot => $actual) {
                if (($bisGear[$slot]['item_id'] ?? null) === ($actual['item_id'] ?? null)) {
                    $score++;
                }
            }
            if ($score > $bestScore
                || ($score === $bestScore && $candidate->hero_talent === null)) {
                $bestScore = $score;
                $best = $candidate;
            }
        }
        return $best;
    }

    /**
     * Public access for bulk callers building their own (class|spec)
     * lookup keys.
     */
    public function classKey(Member $member): ?string
    {
        return self::CLASS_NORMALISE[strtoupper((string) $member->class)] ?? null;
    }

    /**
     * Pure comparison: no DB lookups, callers pre-load both sides.
     * Useful for the roster's bulk path where we'd N+1 otherwise.
     *
     * @param  array<string,mixed>  $rioRaw
     */
    public function compareWithData(Member $member, array $rioRaw, BisProfile $profile, ?\Carbon\CarbonInterface $capturedAt = null): array
    {
        $class = self::CLASS_NORMALISE[strtoupper((string) $member->class)] ?? (string) $profile->class;
        $spec = $this->normaliseSpec($rioRaw['active_spec_name'] ?? null) ?? (string) $profile->spec;

        $actualGear = $this->extractActualGear($rioRaw);
        $bisGear = is_array($profile->parsed_data['gear'] ?? null) ? $profile->parsed_data['gear'] : [];

        $slots = [];
        foreach (self::ALL_SLOTS as $slot) {
            $actual = $actualGear[$slot] ?? null;
            $bis = $bisGear[$slot] ?? null;
            if ($actual === null && $bis === null) {
                continue;
            }
            $slots[$slot] = $this->compareSlot($slot, $actual, $bis);
        }

        return [
            'class' => $class,
            'spec' => $spec,
            'profile_name' => (string) $profile->profile_name,
            'profile_gear_ilvl' => is_numeric($profile->parsed_data['gear_ilvl'] ?? null) ? (float) $profile->parsed_data['gear_ilvl'] : null,
            'source' => 'raiderio',
            'source_captured_at' => $capturedAt,
            'slots' => $slots,
            'consumables' => is_array($profile->parsed_data['consumables'] ?? null) ? $profile->parsed_data['consumables'] : [],
        ];
    }

    /**
     * Aggregate counts of actionable issues from a comparison result.
     * "missing" reads as a clear gear-prep failure (slot needs the
     * enchant / sockets need filling); "wrong" / "count_mismatch" is
     * suspicious but the player may have made an intentional choice,
     * so we tally it separately. Total is the sum officers can sort by.
     *
     * @param  array<string,mixed>  $comparison  result of compareWithData()
     * @return array{missing_enchants:int, wrong_enchants:int, missing_gems:int, wrong_gems:int, total:int}
     */
    public function countIssues(array $comparison): array
    {
        $missingEnchants = 0;
        $wrongEnchants = 0;
        $missingGems = 0;
        $wrongGems = 0;
        foreach (($comparison['slots'] ?? []) as $slot) {
            match ($slot['enchant_status'] ?? null) {
                'missing'   => $missingEnchants++,
                'different' => $wrongEnchants++,
                default     => null,
            };
            match ($slot['gems_status'] ?? null) {
                'missing'                       => $missingGems++,
                'different', 'count_mismatch'   => $wrongGems++,
                default                         => null,
            };
        }
        return [
            'missing_enchants' => $missingEnchants,
            'wrong_enchants' => $wrongEnchants,
            'missing_gems' => $missingGems,
            'wrong_gems' => $wrongGems,
            'total' => $missingEnchants + $wrongEnchants + $missingGems + $wrongGems,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function rawArray(MemberSnapshot $snap): ?array
    {
        $raw = $snap->raw_json;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        return is_array($raw) ? $raw : null;
    }

    public function normaliseSpec(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        // 'Frost' -> 'frost', 'Beast Mastery' -> 'beast_mastery'.
        return strtolower(str_replace([' ', '-'], '_', trim($value)));
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string, array{item_id:int, name:?string, enchant_ids:list<int>, gem_ids:list<int>}>
     */
    private function extractActualGear(array $raw): array
    {
        $items = $raw['gear']['items'] ?? null;
        if (! is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $rioSlot => $item) {
            $simcSlot = self::RIO_TO_SIMC_SLOT[$rioSlot] ?? null;
            if ($simcSlot === null || ! is_array($item)) {
                continue;
            }
            $itemId = $item['item_id'] ?? null;
            if (! is_numeric($itemId)) {
                continue;
            }
            $enchants = is_array($item['enchants'] ?? null)
                ? array_values(array_filter($item['enchants'], 'is_int'))
                : [];
            $gems = is_array($item['gems'] ?? null)
                ? array_values(array_filter($item['gems'], 'is_int'))
                : [];
            $out[$simcSlot] = [
                'item_id' => (int) $itemId,
                'name' => is_string($item['name'] ?? null) ? $item['name'] : null,
                'enchant_ids' => $enchants,
                'gem_ids' => $gems,
            ];
        }
        return $out;
    }

    /**
     * @param  array{item_id:int, name:?string, enchant_ids:list<int>, gem_ids:list<int>}|null  $actual
     * @param  array<string,mixed>|null  $bis
     */
    private function compareSlot(string $slot, ?array $actual, ?array $bis): array
    {
        $bisItem = $bis['item_id'] ?? null;
        $bisItemName = $bis['name'] ?? null;
        $bisEnchant = $bis['enchant_id'] ?? null;
        $bisGems = is_array($bis['gem_ids'] ?? null) ? $bis['gem_ids'] : [];

        $actualItem = $actual['item_id'] ?? null;
        $actualItemName = $actual['name'] ?? null;
        $actualEnchants = is_array($actual['enchant_ids'] ?? null) ? $actual['enchant_ids'] : [];
        $actualGems = is_array($actual['gem_ids'] ?? null) ? $actual['gem_ids'] : [];

        return [
            'slot' => $slot,
            'actual_item_id' => $actualItem,
            'actual_item_name' => $actualItemName,
            'bis_item_id' => $bisItem,
            'bis_item_name' => $bisItemName,
            'item_match' => $actualItem !== null && $actualItem === $bisItem,
            'actual_enchant_ids' => $actualEnchants,
            'bis_enchant_id' => $bisEnchant,
            'enchant_status' => $this->enchantStatus($actualEnchants, $bisEnchant),
            'actual_gem_ids' => $actualGems,
            'bis_gem_ids' => $bisGems,
            'gems_status' => $this->gemsStatus($actualGems, $bisGems),
        ];
    }

    /**
     * @param  list<int>  $actual
     */
    private function enchantStatus(array $actual, ?int $bisEnchantId): string
    {
        if ($bisEnchantId === null) {
            return $actual === [] ? 'none_required' : 'extra';
        }
        if ($actual === []) {
            return 'missing';
        }
        return in_array($bisEnchantId, $actual, true) ? 'matched' : 'different';
    }

    /**
     * @param  list<int>  $actual
     * @param  list<int>  $bis
     */
    private function gemsStatus(array $actual, array $bis): string
    {
        if ($bis === []) {
            return $actual === [] ? 'none_required' : 'extra';
        }
        if ($actual === []) {
            return 'missing';
        }
        if (count($actual) !== count($bis)) {
            return 'count_mismatch';
        }
        $a = $actual; $b = $bis;
        sort($a); sort($b);
        return $a === $b ? 'matched' : 'different';
    }
}
