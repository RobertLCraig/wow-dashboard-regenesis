<?php

namespace App\Services\Simc;

/**
 * Parses a SimulationCraft .simc profile into the structured shape we
 * persist on bis_profiles.parsed_data. SimC profile files mix several
 * concerns - APL, talents, gear, consumables - and we only care about
 * the "what canonical gear / enchants / gems is this spec sim'd in"
 * picture.
 *
 * Reference format (Midnight Season 1, abbreviated):
 *
 *   deathknight="MID1_Death_Knight_Frost_Rider"
 *   spec=frost
 *   level=90
 *   ...
 *   potion=potion_of_recklessness_2
 *   flask=flask_of_the_shattered_sun_2
 *   food=silvermoon_parade
 *   augmentation=void_touched
 *   temporary_enchant=main_hand:thalassian_phoenix_oil_2/off_hand:thalassian_phoenix_oil_2
 *   ...
 *   head=relentless_riders_crown,id=249970,bonus_id=40/6935/12676,gem_id=240983
 *   chest=...,enchant_id=7987
 *   ...
 *   # gear_ilvl=288.47
 */
class SimcProfileParser
{
    /**
     * Class names that span two filename / display tokens. Anything
     * else is single-token. Used to split a profile_name like
     * `MID1_Death_Knight_Frost_Rider` into class / spec / hero.
     */
    private const TWO_TOKEN_CLASSES = ['Death_Knight', 'Demon_Hunter'];

    /**
     * SimC's collapsed class identifier (line 1) -> our snake_case
     * canonical form. Matches the values stored in bis_profiles.class.
     */
    private const CLASS_NORMALISE = [
        'deathknight'  => 'death_knight',
        'demonhunter'  => 'demon_hunter',
        'druid'        => 'druid',
        'evoker'       => 'evoker',
        'hunter'       => 'hunter',
        'mage'         => 'mage',
        'monk'         => 'monk',
        'paladin'      => 'paladin',
        'priest'       => 'priest',
        'rogue'        => 'rogue',
        'shaman'       => 'shaman',
        'warlock'      => 'warlock',
        'warrior'      => 'warrior',
    ];

    /**
     * Slot keys SimC uses on gear lines. Anything else (potion, food,
     * etc.) goes into the consumables bucket.
     */
    private const GEAR_SLOTS = [
        'head', 'neck', 'shoulders', 'back', 'chest', 'wrists',
        'hands', 'waist', 'legs', 'feet',
        'finger1', 'finger2', 'trinket1', 'trinket2',
        'main_hand', 'off_hand',
    ];

    private const CONSUMABLE_KEYS = [
        'potion', 'flask', 'food', 'augmentation',
    ];

    /**
     * @return array{
     *   class: ?string,
     *   spec: ?string,
     *   hero_talent: ?string,
     *   profile_name: ?string,
     *   level: ?int,
     *   gear_ilvl: ?float,
     *   gear: array<string, array{slot:string, name:string, item_id:int, ilevel:?int, bonus_ids:list<int>, gem_ids:list<int>, enchant_id:?int}>,
     *   consumables: array<string, string>,
     * }
     */
    public function parse(string $contents): array
    {
        $class = null;
        $spec = null;
        $profileName = null;
        $level = null;
        $gearIlvl = null;
        $gear = [];
        $consumables = [];

        foreach (preg_split('/\R/', $contents) as $rawLine) {
            $line = trim($rawLine);
            if ($line === '') {
                continue;
            }

            // Comments only matter for the gear_ilvl summary footer.
            if ($line[0] === '#') {
                if (preg_match('/^#\s*gear_ilvl\s*=\s*([\d.]+)/', $line, $m)) {
                    $gearIlvl = (float) $m[1];
                }
                continue;
            }

            // Class declaration: `<class>="<profile_name>"`. Always the
            // first non-comment line in our profiles.
            if ($class === null && preg_match('/^([a-z]+)="(.+)"$/', $line, $m)) {
                $rawClass = $m[1];
                $class = self::CLASS_NORMALISE[$rawClass] ?? null;
                $profileName = $m[2];
                continue;
            }

            // key=value lines. Everything before the first = is the key.
            if (! str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = strtolower(trim($key));
            $value = trim($value);

            if ($key === 'spec') {
                $spec = strtolower($value);
                continue;
            }
            if ($key === 'level') {
                $level = is_numeric($value) ? (int) $value : null;
                continue;
            }
            if ($key === 'temporary_enchant') {
                // main_hand:phoenix_oil_2/off_hand:phoenix_oil_2
                foreach (explode('/', $value) as $part) {
                    [$slot, $name] = array_pad(explode(':', $part, 2), 2, null);
                    if ($slot && $name) {
                        $consumables['temporary_enchant_' . trim($slot)] = trim($name);
                    }
                }
                continue;
            }
            if (in_array($key, self::CONSUMABLE_KEYS, true)) {
                $consumables[$key] = $value;
                continue;
            }
            if (in_array($key, self::GEAR_SLOTS, true)) {
                $item = $this->parseGearLine($key, $value);
                if ($item !== null) {
                    $gear[$key] = $item;
                }
                continue;
            }
            // Anything else (talents, role, race, position, source,
            // actions, ...) is irrelevant to BiS comparison.
        }

        $heroTalent = $this->heroTalentFrom($profileName);

        return [
            'class' => $class,
            'spec' => $spec,
            'hero_talent' => $heroTalent,
            'profile_name' => $profileName,
            'level' => $level,
            'gear_ilvl' => $gearIlvl,
            'gear' => $gear,
            'consumables' => $consumables,
        ];
    }

    /**
     * Gear line value, after the slot=. Format:
     *   <name>,id=<int>[,ilevel=<int>][,bonus_id=<int>/<int>/...][,gem_id=<int>[/<int>]][,enchant_id=<int>][,context=<int>]
     *
     * @return array{slot:string, name:string, item_id:int, ilevel:?int, bonus_ids:list<int>, gem_ids:list<int>, enchant_id:?int}|null
     */
    private function parseGearLine(string $slot, string $value): ?array
    {
        $parts = explode(',', $value);
        if ($parts === []) {
            return null;
        }

        $name = trim(array_shift($parts));
        $itemId = null;
        $ilevel = null;
        $bonusIds = [];
        $gemIds = [];
        $enchantId = null;

        foreach ($parts as $part) {
            if (! str_contains($part, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $part, 2);
            $k = strtolower(trim($k));
            $v = trim($v);
            switch ($k) {
                case 'id':
                    $itemId = is_numeric($v) ? (int) $v : null;
                    break;
                case 'ilevel':
                    $ilevel = is_numeric($v) ? (int) $v : null;
                    break;
                case 'bonus_id':
                    $bonusIds = array_values(array_map(intval(...), array_filter(explode('/', $v), 'is_numeric')));
                    break;
                case 'gem_id':
                    $gemIds = array_values(array_map(intval(...), array_filter(explode('/', $v), 'is_numeric')));
                    break;
                case 'enchant_id':
                    $enchantId = is_numeric($v) ? (int) $v : null;
                    break;
            }
        }

        // An item line without an id isn't useful for BiS comparison;
        // drop rather than persist a half-row.
        if ($itemId === null || $name === '') {
            return null;
        }

        return [
            'slot' => $slot,
            'name' => $name,
            'item_id' => $itemId,
            'ilevel' => $ilevel,
            'bonus_ids' => $bonusIds,
            'gem_ids' => $gemIds,
            'enchant_id' => $enchantId,
        ];
    }

    /**
     * Pull the hero-talent suffix (if any) out of the profile name.
     * Examples:
     *   MID1_Death_Knight_Frost          -> null
     *   MID1_Death_Knight_Frost_Rider    -> 'rider'
     *   MID1_Demon_Hunter_Devourer_Void-Scarred -> 'void_scarred'
     *   MID1_Druid_Balance               -> null
     */
    private function heroTalentFrom(?string $profileName): ?string
    {
        if ($profileName === null) {
            return null;
        }
        // Drop the leading tier label (MID1_, T31_, etc.).
        $body = preg_replace('/^[A-Z0-9]+_/', '', $profileName) ?? $profileName;

        // Two-token class names take three tokens for class+spec; one-
        // token classes take two. Anything past that is the hero.
        $classOffset = 1;
        foreach (self::TWO_TOKEN_CLASSES as $two) {
            if (str_starts_with($body, $two . '_')) {
                $classOffset = 2;
                break;
            }
        }
        $tokens = explode('_', $body);
        $afterSpec = array_slice($tokens, $classOffset + 1);
        if ($afterSpec === []) {
            return null;
        }
        // Replace hyphen with underscore so e.g. "Void-Scarred" lands
        // alongside "void_scarred" in the unique key.
        return strtolower(str_replace('-', '_', implode('_', $afterSpec)));
    }
}
