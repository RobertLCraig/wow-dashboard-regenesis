<?php

namespace App\Services\Composition;

/**
 * Map a (class, spec) pair to a composition role: tank | healer |
 * melee | ranged. The composition planner uses this to bucket
 * roster members so a raid lead can see comp at a glance.
 *
 * Augmentation evoker is bucketed as ranged (closest fit; it's a
 * support spec but it parses on dpsRankings and stands at range).
 *
 * Inputs are case-insensitive; both class and spec come in as the
 * upper or mixed-case strings WCL uses (e.g. "DemonHunter",
 * "Vengeance"). Returns null if the pair isn't recognised so callers
 * can fall back to whatever role the parses table reports.
 */
class SpecRoleMap
{
    public const ROLE_TANK   = 'tank';
    public const ROLE_HEALER = 'healer';
    public const ROLE_MELEE  = 'melee';
    public const ROLE_RANGED = 'ranged';

    /** @var array<string, array<string, string>>  class => spec => role */
    private const MAP = [
        'deathknight' => [
            'blood' => self::ROLE_TANK,
            'frost' => self::ROLE_MELEE,
            'unholy' => self::ROLE_MELEE,
        ],
        'demonhunter' => [
            'havoc' => self::ROLE_MELEE,
            'vengeance' => self::ROLE_TANK,
        ],
        'druid' => [
            'balance' => self::ROLE_RANGED,
            'feral' => self::ROLE_MELEE,
            'guardian' => self::ROLE_TANK,
            'restoration' => self::ROLE_HEALER,
        ],
        'evoker' => [
            'devastation' => self::ROLE_RANGED,
            'preservation' => self::ROLE_HEALER,
            'augmentation' => self::ROLE_RANGED,
        ],
        'hunter' => [
            'beastmastery' => self::ROLE_RANGED,
            'beast mastery' => self::ROLE_RANGED,
            'marksmanship' => self::ROLE_RANGED,
            'survival' => self::ROLE_MELEE,
        ],
        'mage' => [
            'arcane' => self::ROLE_RANGED,
            'fire' => self::ROLE_RANGED,
            'frost' => self::ROLE_RANGED,
        ],
        'monk' => [
            'brewmaster' => self::ROLE_TANK,
            'mistweaver' => self::ROLE_HEALER,
            'windwalker' => self::ROLE_MELEE,
        ],
        'paladin' => [
            'holy' => self::ROLE_HEALER,
            'protection' => self::ROLE_TANK,
            'retribution' => self::ROLE_MELEE,
        ],
        'priest' => [
            'discipline' => self::ROLE_HEALER,
            'holy' => self::ROLE_HEALER,
            'shadow' => self::ROLE_RANGED,
        ],
        'rogue' => [
            'assassination' => self::ROLE_MELEE,
            'outlaw' => self::ROLE_MELEE,
            'subtlety' => self::ROLE_MELEE,
        ],
        'shaman' => [
            'elemental' => self::ROLE_RANGED,
            'enhancement' => self::ROLE_MELEE,
            'restoration' => self::ROLE_HEALER,
        ],
        'warlock' => [
            'affliction' => self::ROLE_RANGED,
            'demonology' => self::ROLE_RANGED,
            'destruction' => self::ROLE_RANGED,
        ],
        'warrior' => [
            'arms' => self::ROLE_MELEE,
            'fury' => self::ROLE_MELEE,
            'protection' => self::ROLE_TANK,
        ],
    ];

    public static function role(?string $class, ?string $spec): ?string
    {
        if (! $class || ! $spec) {
            return null;
        }
        $c = strtolower(str_replace([' ', '-', '_'], '', $class));
        $s = strtolower(trim($spec));
        return self::MAP[$c][$s] ?? null;
    }

    /**
     * Order roles read top-to-bottom in a comp view (tanks first,
     * then healers, then DPS).
     *
     * @return list<string>
     */
    public static function orderedRoles(): array
    {
        return [self::ROLE_TANK, self::ROLE_HEALER, self::ROLE_MELEE, self::ROLE_RANGED];
    }

    public static function label(string $role): string
    {
        return match ($role) {
            self::ROLE_TANK   => 'Tanks',
            self::ROLE_HEALER => 'Healers',
            self::ROLE_MELEE  => 'Melee DPS',
            self::ROLE_RANGED => 'Ranged DPS',
            default           => ucfirst($role),
        };
    }
}
