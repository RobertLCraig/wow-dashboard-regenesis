<?php

namespace App\Services\Bis;

use App\Models\BisProfile;
use App\Models\Member;
use App\Models\MemberEquipmentSnapshot;
use App\Models\MemberSnapshot;
use App\Models\Snapshot;
use App\Models\WclActorParse;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Compares a member's actual gear against their class+spec BiS profile.
 *
 * Source-of-truth precedence for the player's actual gear:
 *   1. Blizzard /character/equipment      (member_equipment_snapshots)
 *   2. Raider.IO /profile gear            (member_snapshots, source=raiderio)
 *   3. Most-recent WCL parse raw_json.gear (per-fight snapshot)
 *
 * Blizzard wins because it's the upstream source - RIO and wowaudit
 * scrape their data from Blizzard themselves, often hours behind, and
 * RIO only indexes characters that have logged a recent enough action
 * for them to have noticed. Falling back keeps us covering the long
 * tail (alts, fresh joiners, name changes) that RIO/wowaudit miss.
 *
 * Spec is resolved on the same fallback chain but ordered independently
 * since the gear and spec sources don't have to agree:
 *   1. Blizzard profile-summary active_spec.name
 *   2. Raider.IO active_spec_name
 *   3. Most-recent WCL parse actor_spec
 *
 * BiS reference data continues to come from bis_profiles, populated
 * from SimulationCraft profile files. Returns null when any input is
 * missing (no gear sample anywhere, can't determine spec, no matching
 * BiS profile). The character page renders a placeholder in that case.
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

    /**
     * Blizzard /character/equipment uses uppercase enum slot types
     * (per the slot.type field). FINGER_1/TRINKET_1 carry an underscore;
     * SimC drops it.
     */
    private const BLIZZARD_TO_SIMC_SLOT = [
        'HEAD'      => 'head',
        'NECK'      => 'neck',
        'SHOULDER'  => 'shoulders',
        'BACK'      => 'back',
        'CHEST'     => 'chest',
        'WRIST'     => 'wrists',
        'HANDS'     => 'hands',
        'WAIST'     => 'waist',
        'LEGS'      => 'legs',
        'FEET'      => 'feet',
        'FINGER_1'  => 'finger1',
        'FINGER_2'  => 'finger2',
        'TRINKET_1' => 'trinket1',
        'TRINKET_2' => 'trinket2',
        'MAIN_HAND' => 'main_hand',
        'OFF_HAND'  => 'off_hand',
    ];

    /**
     * WCL parses use numeric slot indices; this matches WoW's internal
     * inventory slot constants so the mapping is stable across patches.
     * Tabard (19) and shirt (3) are intentionally absent - never relevant
     * to a BiS comparison. Slot 5 (chest) is the body chest, slot 4 is
     * shirt and excluded.
     */
    private const WCL_SLOT_TO_SIMC = [
        0  => 'head',
        1  => 'neck',
        2  => 'shoulders',
        14 => 'back',
        4  => 'chest',
        8  => 'wrists',
        9  => 'hands',
        5  => 'waist',
        6  => 'legs',
        7  => 'feet',
        10 => 'finger1',
        11 => 'finger2',
        12 => 'trinket1',
        13 => 'trinket2',
        15 => 'main_hand',
        16 => 'off_hand',
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
     *   source_captured_at:?CarbonInterface,
     *   slots:array<string, array<string,mixed>>,
     *   consumables:array<string,string>,
     * }|null
     */
    public function compareForMember(Member $member): ?array
    {
        $reading = $this->resolveGearReading($member);
        if ($reading === null) {
            return null;
        }

        $spec = $reading['spec'] ?? $this->resolveSpec($member);
        $class = $this->classKey($member);
        if ($spec === null || $class === null) {
            return null;
        }

        $candidates = BisProfile::query()
            ->where('class', $class)
            ->where('spec', $spec)
            ->get();
        $profile = $this->pickBestProfileFromGear($candidates, $reading['gear']);
        if ($profile === null) {
            return null;
        }

        return $this->buildComparison(
            class: $class,
            spec: $spec,
            actualGear: $reading['gear'],
            profile: $profile,
            sourceLabel: $reading['source'],
            capturedAt: $reading['captured_at'],
        );
    }

    /**
     * Resolve the player's currently-equipped gear from whichever
     * source has fresh data, in priority order. The reading carries
     * the canonical-slot gear array, the source label for the UI, the
     * timestamp for "X minutes ago" formatting, and (when the source
     * also exposes spec) a spec hint so we don't have to make a
     * second resolution pass.
     *
     * @return array{gear:array<string, array{item_id:int,name:?string,enchant_ids:list<int>,gem_ids:list<int>}>, source:string, captured_at:?CarbonInterface, spec:?string}|null
     */
    public function resolveGearReading(Member $member): ?array
    {
        if ($r = $this->readingFromBlizzardEquipment($member)) {
            return $r;
        }
        if ($r = $this->readingFromRaiderio($member)) {
            return $r;
        }
        if ($r = $this->readingFromWcl($member)) {
            return $r;
        }
        return null;
    }

    /**
     * Resolve spec independently of gear. Same fallback chain because
     * the player's active spec is also a Blizzard-authoritative fact
     * that RIO and WCL only mirror.
     */
    public function resolveSpec(Member $member): ?string
    {
        $blizzard = $this->latestRawJson($member, Snapshot::SOURCE_BLIZZARD);
        if (is_array($blizzard)) {
            $name = is_array($blizzard['active_spec'] ?? null) ? ($blizzard['active_spec']['name'] ?? null) : null;
            $spec = $this->normaliseSpec($name);
            if ($spec !== null) {
                return $spec;
            }
        }

        $rio = $this->latestRawJson($member, Snapshot::SOURCE_RAIDERIO);
        if (is_array($rio)) {
            $spec = $this->normaliseSpec($rio['active_spec_name'] ?? null);
            if ($spec !== null) {
                return $spec;
            }
        }

        $wclSpec = WclActorParse::query()
            ->where('member_id', $member->id)
            ->orderByDesc('id')
            ->value('actor_spec');
        if (is_string($wclSpec) && $wclSpec !== '') {
            // WCL stores "Class-Spec" e.g. "Shaman-Restoration".
            $parts = explode('-', $wclSpec, 2);
            if (count($parts) === 2) {
                return $this->normaliseSpec($parts[1]);
            }
        }

        return null;
    }

    /**
     * Score each candidate profile by exact item-id overlap with the
     * player's actual gear. Highest score wins; ties prefer the default
     * profile so a player with no clearly-aligned variant still gets a
     * sensible fallback.
     *
     * @param  \Illuminate\Support\Collection<int,BisProfile>  $candidates
     * @param  array<string, array{item_id:int,name:?string,enchant_ids:list<int>,gem_ids:list<int>}>  $actualGear
     */
    public function pickBestProfileFromGear(\Illuminate\Support\Collection $candidates, array $actualGear): ?BisProfile
    {
        if ($candidates->isEmpty()) {
            return null;
        }
        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        $default = $candidates->first(fn (BisProfile $p) => $p->hero_talent === null);
        if ($actualGear === []) {
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

    public function normaliseSpec(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        // 'Frost' -> 'frost', 'Beast Mastery' -> 'beast_mastery'.
        return strtolower(str_replace([' ', '-'], '_', trim($value)));
    }

    /**
     * Aggregate counts of actionable issues from a comparison result.
     * "missing" reads as a clear gear-prep failure; "wrong" / "count_mismatch"
     * is suspicious but the player may have made an intentional choice.
     *
     * @param  array<string,mixed>  $comparison  result of compareForMember()
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

    // ---------------------------------------------------------------
    // Source-specific gear extraction
    // ---------------------------------------------------------------

    /**
     * @return array{gear:array<string, array{item_id:int,name:?string,enchant_ids:list<int>,gem_ids:list<int>}>, source:string, captured_at:?CarbonInterface, spec:?string}|null
     */
    private function readingFromBlizzardEquipment(Member $member): ?array
    {
        $snap = MemberEquipmentSnapshot::query()
            ->where('member_id', $member->id)
            ->with('snapshot:id,captured_at,source')
            ->orderByDesc('id')
            ->first();
        if ($snap === null || ! is_array($snap->pieces) || $snap->pieces === []) {
            return null;
        }

        $gear = $this->extractFromBlizzardEquipment($snap->pieces);
        if ($gear === []) {
            return null;
        }

        return [
            'gear' => $gear,
            'source' => 'blizzard',
            'captured_at' => $snap->snapshot?->captured_at,
            'spec' => null, // Spec lives on the profile-summary snapshot, resolved separately.
        ];
    }

    /**
     * @return array{gear:array<string, array{item_id:int,name:?string,enchant_ids:list<int>,gem_ids:list<int>}>, source:string, captured_at:?CarbonInterface, spec:?string}|null
     */
    private function readingFromRaiderio(Member $member): ?array
    {
        $snap = MemberSnapshot::query()
            ->whereHas('snapshot', fn ($q) => $q->where('source', Snapshot::SOURCE_RAIDERIO))
            ->where('member_id', $member->id)
            ->with('snapshot:id,source,captured_at')
            ->orderByDesc('id')
            ->first();
        if ($snap === null) {
            return null;
        }
        $raw = $this->rawArray($snap);
        if ($raw === null) {
            return null;
        }

        $gear = $this->extractFromRio($raw);
        $spec = $this->normaliseSpec($raw['active_spec_name'] ?? null);
        // Empty gear is still a usable reading when we at least have a
        // spec - the comparison renders BiS-side data with empty "Have"
        // rows, which is informative for fresh-dinged alts.
        if ($gear === [] && $spec === null) {
            return null;
        }

        return [
            'gear' => $gear,
            'source' => 'raiderio',
            'captured_at' => $snap->snapshot?->captured_at,
            'spec' => $spec,
        ];
    }

    /**
     * Per-parse gear from the most recent WCL log. Less authoritative
     * than Blizzard/RIO (the player may have respec'd or reforged
     * between the parse and now) but still better than rendering
     * nothing at all - and for active raiders it's never more than a
     * few days stale.
     *
     * @return array{gear:array<string, array{item_id:int,name:?string,enchant_ids:list<int>,gem_ids:list<int>}>, source:string, captured_at:?CarbonInterface, spec:?string}|null
     */
    private function readingFromWcl(Member $member): ?array
    {
        $parse = WclActorParse::query()
            ->where('member_id', $member->id)
            ->with('fight:id,wcl_report_id,start_time')
            ->orderByDesc('id')
            ->first();
        if ($parse === null) {
            return null;
        }

        $raw = $parse->raw_json;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw)) {
            return null;
        }

        $gear = $this->extractFromWcl($raw);
        if ($gear === []) {
            return null;
        }

        $spec = null;
        if (is_string($parse->actor_spec) && $parse->actor_spec !== '') {
            $parts = explode('-', $parse->actor_spec, 2);
            if (count($parts) === 2) {
                $spec = $this->normaliseSpec($parts[1]);
            }
        }

        return [
            'gear' => $gear,
            'source' => 'wcl',
            'captured_at' => $parse->fight?->start_time,
            'spec' => $spec,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $pieces  Blizzard equipped_items array
     * @return array<string, array{item_id:int,name:?string,enchant_ids:list<int>,gem_ids:list<int>}>
     */
    public function extractFromBlizzardEquipment(array $pieces): array
    {
        $out = [];
        foreach ($pieces as $piece) {
            if (! is_array($piece)) {
                continue;
            }
            $blizzSlot = $piece['slot']['type'] ?? null;
            $simcSlot = is_string($blizzSlot) ? (self::BLIZZARD_TO_SIMC_SLOT[$blizzSlot] ?? null) : null;
            if ($simcSlot === null) {
                continue;
            }
            $itemId = $piece['item']['id'] ?? null;
            if (! is_numeric($itemId)) {
                continue;
            }
            $enchants = [];
            foreach (is_array($piece['enchantments'] ?? null) ? $piece['enchantments'] : [] as $row) {
                if (is_array($row) && is_numeric($row['enchantment_id'] ?? null) && (int) $row['enchantment_id'] > 0) {
                    $enchants[] = (int) $row['enchantment_id'];
                }
            }
            $gems = [];
            foreach (is_array($piece['sockets'] ?? null) ? $piece['sockets'] : [] as $socket) {
                if (is_array($socket) && is_array($socket['item'] ?? null) && is_numeric($socket['item']['id'] ?? null)) {
                    $gems[] = (int) $socket['item']['id'];
                }
            }
            $out[$simcSlot] = [
                'item_id' => (int) $itemId,
                'name' => is_string($piece['name'] ?? null) ? $piece['name'] : null,
                'enchant_ids' => $enchants,
                'gem_ids' => $gems,
            ];
        }
        return $out;
    }

    /**
     * @param  array<string,mixed>  $raw  RIO /profile body
     * @return array<string, array{item_id:int,name:?string,enchant_ids:list<int>,gem_ids:list<int>}>
     */
    public function extractFromRio(array $raw): array
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
     * @param  array<string,mixed>  $raw  WCL parse raw_json
     * @return array<string, array{item_id:int,name:?string,enchant_ids:list<int>,gem_ids:list<int>}>
     */
    public function extractFromWcl(array $raw): array
    {
        $items = $raw['gear'] ?? null;
        if (! is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $slot = $item['slot'] ?? null;
            $simcSlot = is_int($slot) ? (self::WCL_SLOT_TO_SIMC[$slot] ?? null) : null;
            if ($simcSlot === null) {
                continue;
            }
            $itemId = $item['id'] ?? null;
            if (! is_numeric($itemId) || (int) $itemId <= 0) {
                continue;
            }
            $enchants = [];
            $enchantId = $item['permanentEnchant'] ?? null;
            if (is_numeric($enchantId) && (int) $enchantId > 0) {
                $enchants[] = (int) $enchantId;
            }
            $gems = [];
            foreach (is_array($item['gems'] ?? null) ? $item['gems'] : [] as $gem) {
                $gid = is_array($gem) ? ($gem['id'] ?? null) : $gem;
                if (is_numeric($gid) && (int) $gid > 0) {
                    $gems[] = (int) $gid;
                }
            }
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
     * Build the per-slot comparison output. Pure: no DB lookups.
     *
     * @param  array<string, array{item_id:int,name:?string,enchant_ids:list<int>,gem_ids:list<int>}>  $actualGear
     * @return array{class:string, spec:string, profile_name:string, profile_gear_ilvl:?float, source:string, source_captured_at:?CarbonInterface, slots:array<string,array<string,mixed>>, consumables:array<string,string>}
     */
    public function buildComparison(string $class, string $spec, array $actualGear, BisProfile $profile, string $sourceLabel, ?CarbonInterface $capturedAt): array
    {
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
            'source' => $sourceLabel,
            'source_captured_at' => $capturedAt,
            'slots' => $slots,
            'consumables' => is_array($profile->parsed_data['consumables'] ?? null) ? $profile->parsed_data['consumables'] : [],
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

    /**
     * @return array<string,mixed>|null
     */
    private function latestRawJson(Member $member, string $source): ?array
    {
        $snap = MemberSnapshot::query()
            ->whereHas('snapshot', fn ($q) => $q->where('source', $source))
            ->where('member_id', $member->id)
            ->orderByDesc('id')
            ->first();
        return $snap ? $this->rawArray($snap) : null;
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
