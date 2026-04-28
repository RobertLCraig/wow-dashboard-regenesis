<?php

namespace App\Services\Teams;

use App\Models\Member;
use App\Models\MemberTeam;
use App\Models\TeamMapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Read + write side of the team mapping table. Resolves an in-game rank
 * name or a list of Discord role IDs to a team value ('mythic',
 * 'heroic', etc.) using the rules officers configured at /admin/teams,
 * and keeps the member_teams pivot in sync with those rules.
 *
 * Lookups are cached in memory for the lifetime of the request (cheap
 * hit-rate for the GRM normalizer that processes ~100 members in a row)
 * and in the application cache for cross-request reuse. The admin
 * controller calls flush() after writes so changes take effect on the
 * next read.
 *
 * Override semantics: a member with at least one MemberTeam row that has
 * is_override = true is treated as fully officer-managed for team
 * membership. The recompute leaves those members untouched. Clearing the
 * override deletes the override rows and re-derives from rank.
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
     * Recompute member_teams for every member in the given guild whose
     * team rows are auto-derived from rank. Members with any override
     * row are left alone so officer-set teams stick across rank changes
     * and mapping edits.
     *
     * Returns the count of members whose pivot rows actually changed.
     */
    public function recomputeMembers(string $guildKey): int
    {
        $this->flush();
        $map = $this->load();
        $rankToTeam = $map['grm_rank'];

        $updated = 0;

        Member::query()
            ->forGuild($guildKey)
            ->chunkById(200, function ($members) use ($rankToTeam, &$updated) {
                $ids = $members->pluck('id')->all();
                $rowsByMember = MemberTeam::query()
                    ->whereIn('member_id', $ids)
                    ->get()
                    ->groupBy('member_id');

                foreach ($members as $member) {
                    $rows = $rowsByMember->get($member->id, collect());
                    $hasOverride = $rows->contains(fn (MemberTeam $r) => (bool) $r->is_override);
                    if ($hasOverride) {
                        // Officer has manually set this member's teams;
                        // rank changes don't disturb the override.
                        continue;
                    }
                    $rankTeam = $rankToTeam[$member->rank_name] ?? null;
                    $expected = $rankTeam !== null ? [$rankTeam] : [];
                    $existing = $rows->pluck('team')->all();

                    sort($existing);
                    $sortedExpected = $expected;
                    sort($sortedExpected);

                    if ($existing === $sortedExpected) {
                        continue;
                    }

                    $this->replaceRankRows($member->id, $expected);
                    $updated++;
                }
            });

        return $updated;
    }

    /**
     * Re-derive rank-based rows for a single member, only if the member
     * has no override rows. Called from the GRM normalizer right after
     * an upsert so newly-ingested rank changes propagate to member_teams
     * without requiring a full recompute pass.
     */
    public function syncRankRowsForMember(Member $member): void
    {
        $hasOverride = MemberTeam::query()
            ->where('member_id', $member->id)
            ->where('is_override', true)
            ->exists();
        if ($hasOverride) {
            return;
        }
        $rankTeam = $this->forRank($member->rank_name);
        $expected = $rankTeam !== null ? [$rankTeam] : [];

        $existing = MemberTeam::query()
            ->where('member_id', $member->id)
            ->pluck('team')
            ->all();
        sort($existing);
        $sortedExpected = $expected;
        sort($sortedExpected);
        if ($existing === $sortedExpected) {
            return;
        }
        $this->replaceRankRows($member->id, $expected);
    }

    /**
     * Set the supplied teams as overrides for the given member. Wipes
     * any existing rows (override or not) and writes fresh override
     * rows so the new selection fully defines the member's teams.
     *
     * Empty selection routes through clearOverrides(): "no teams ticked"
     * is the natural UI signal for "revert to rank-derived". Locking a
     * member to zero teams (rare; would mostly affect retired officers)
     * isn't supported in v1.
     *
     * @param  list<string>  $teams
     */
    public function setOverrides(Member $member, array $teams, ?int $userId = null): void
    {
        $valid = array_values(array_unique(array_filter(
            $teams,
            fn ($t) => is_string($t) && in_array($t, TeamMapping::TEAMS, true)
        )));

        if ($valid === []) {
            $this->clearOverrides($member);
            return;
        }

        DB::transaction(function () use ($member, $valid, $userId) {
            MemberTeam::query()->where('member_id', $member->id)->delete();
            foreach ($valid as $team) {
                MemberTeam::query()->create([
                    'member_id' => $member->id,
                    'team' => $team,
                    'is_override' => true,
                    'set_by_user_id' => $userId,
                ]);
            }
        });
    }

    /**
     * Drop all override rows for the member and re-derive from rank.
     * Inverse of setOverrides().
     */
    public function clearOverrides(Member $member): void
    {
        DB::transaction(function () use ($member) {
            MemberTeam::query()
                ->where('member_id', $member->id)
                ->where('is_override', true)
                ->delete();
            $rankTeam = $this->forRank($member->rank_name);
            $expected = $rankTeam !== null ? [$rankTeam] : [];
            $this->replaceRankRows($member->id, $expected);
        });
    }

    /**
     * Replace this member's rank-derived (is_override = false) rows with
     * exactly the supplied team list. Override rows are not touched here;
     * callers must ensure the member has none before calling, or accept
     * a mixed state (which the read side can still handle correctly).
     *
     * @param  list<string>  $teams
     */
    private function replaceRankRows(int $memberId, array $teams): void
    {
        DB::transaction(function () use ($memberId, $teams) {
            MemberTeam::query()
                ->where('member_id', $memberId)
                ->where('is_override', false)
                ->delete();
            foreach (array_values(array_unique($teams)) as $team) {
                MemberTeam::query()->updateOrCreate(
                    ['member_id' => $memberId, 'team' => $team],
                    ['is_override' => false]
                );
            }
        });
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
