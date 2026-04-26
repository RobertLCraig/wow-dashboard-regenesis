<?php

namespace App\Services\Teams;

use App\Models\Member;
use App\Models\TeamMapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Read side of the team mapping table. Resolves an in-game rank name or
 * a list of Discord role IDs to a team ('mythic', 'heroic', etc.) using
 * the rules officers configured at /admin/teams.
 *
 * Lookups are cached in memory for the lifetime of the request (cheap
 * hit-rate for the GRM normalizer that processes ~100 members in a row)
 * and in the application cache for cross-request reuse. The admin
 * controller calls flush() after writes so changes take effect on the
 * next read.
 */
class TeamResolver
{
    private const CACHE_KEY = 'team_mappings.lookup.v1';
    private const CACHE_TTL_MINUTES = 60;

    /** @var array{grm_rank: array<string,?string>, discord_role: array<string,array{team:?string,priority:int}>}|null */
    private ?array $inMemory = null;

    public function forRank(?string $rankName): ?string
    {
        if ($rankName === null || $rankName === '') {
            return null;
        }
        $map = $this->load();
        return $map['grm_rank'][$rankName] ?? null;
    }

    /**
     * Highest-priority match wins when a user holds multiple mapped
     * roles. Ties broken arbitrarily by insertion order.
     *
     * @param  array<int|string,string|int>  $roleIds  Snowflakes Discord
     *         returned for the user. Coerced to string before lookup.
     */
    public function forRoleIds(array $roleIds): ?string
    {
        if ($roleIds === []) {
            return null;
        }
        $map = $this->load();
        $best = null;
        $bestPriority = -1;
        foreach ($roleIds as $roleId) {
            $entry = $map['discord_role'][(string) $roleId] ?? null;
            if ($entry === null) {
                continue;
            }
            if ($entry['priority'] > $bestPriority) {
                $best = $entry['team'];
                $bestPriority = $entry['priority'];
            }
        }
        return $best;
    }

    public function flush(): void
    {
        $this->inMemory = null;
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Recompute members.team for every member in the given guild based
     * on their current rank_name + the latest mapping table. Returns
     * the count of rows updated.
     */
    public function recomputeMembers(string $guildKey): int
    {
        $this->flush();
        $map = $this->load();
        $rankToTeam = $map['grm_rank'];

        $updated = 0;
        // Ranks the officers have classified (including those they
        // explicitly mapped to null).
        $known = array_keys($rankToTeam);

        // Members whose current rank is in the mapping table but whose
        // team column doesn't match the mapping's value (or is null).
        Member::query()
            ->forGuild($guildKey)
            ->whereIn('rank_name', $known)
            ->chunkById(200, function ($members) use ($rankToTeam, &$updated) {
                foreach ($members as $member) {
                    $expected = $rankToTeam[$member->rank_name] ?? null;
                    if ($member->team !== $expected) {
                        $member->forceFill(['team' => $expected])->saveQuietly();
                        $updated++;
                    }
                }
            });

        // Members with a rank that's no longer mapped should have team
        // cleared (officer removed the mapping).
        Member::query()
            ->forGuild($guildKey)
            ->whereNotNull('team')
            ->whereNotIn('rank_name', $known)
            ->chunkById(200, function ($members) use (&$updated) {
                foreach ($members as $member) {
                    $member->forceFill(['team' => null])->saveQuietly();
                    $updated++;
                }
            });

        return $updated;
    }

    /**
     * @return array{grm_rank: array<string,?string>, discord_role: array<string,array{team:?string,priority:int}>}
     */
    private function load(): array
    {
        if ($this->inMemory !== null) {
            return $this->inMemory;
        }
        $cached = Cache::remember(self::CACHE_KEY, now()->addMinutes(self::CACHE_TTL_MINUTES), function () {
            $rows = TeamMapping::query()->get(['source', 'key', 'team', 'priority']);
            $rank = [];
            $role = [];
            foreach ($rows as $row) {
                if ($row->source === TeamMapping::SOURCE_GRM_RANK) {
                    $rank[$row->key] = $row->team;
                } elseif ($row->source === TeamMapping::SOURCE_DISCORD_ROLE) {
                    $role[$row->key] = [
                        'team' => $row->team,
                        'priority' => (int) $row->priority,
                    ];
                }
            }
            return ['grm_rank' => $rank, 'discord_role' => $role];
        });
        return $this->inMemory = $cached;
    }
}
