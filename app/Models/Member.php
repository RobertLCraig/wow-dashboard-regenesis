<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Member extends Model
{
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_LEFT = 'left';
    public const STATUS_BANNED = 'banned';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'join_date' => 'date',
            'birthday' => 'date',
            'last_online_at' => 'datetime',
            'banned_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_blizzard_seen_at' => 'datetime',
            'is_online' => 'boolean',
            'is_mobile' => 'boolean',
            'join_date_unknown' => 'boolean',
            'hardcore_is_dead' => 'boolean',
            'recommend_promote' => 'boolean',
            'recommend_demote' => 'boolean',
            'recommend_kick' => 'boolean',
            'recommend_special' => 'boolean',
            'is_valid_at_blizzard' => 'boolean',
        ];
    }

    public function altGroup(): BelongsTo
    {
        return $this->belongsTo(AltGroup::class);
    }

    public function main(): BelongsTo
    {
        return $this->belongsTo(self::class, 'main_member_id');
    }

    public function alts(): HasMany
    {
        return $this->hasMany(self::class, 'main_member_id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(MemberSnapshot::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(MemberEvent::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(MemberAction::class);
    }

    public function teams(): HasMany
    {
        return $this->hasMany(MemberTeam::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeForGuild(Builder $query, string $guildKey): Builder
    {
        return $query->where('guild_key', $guildKey);
    }

    /**
     * Members who have at least one row in member_teams for any of the
     * supplied team values. Replaces the old whereIn('team', ...) usage
     * once a member can belong to several teams at once.
     *
     * @param  list<string>  $teams
     */
    public function scopeOnAnyTeam(Builder $query, array $teams): Builder
    {
        if ($teams === []) {
            // Empty filter is a contradiction: matches nothing.
            return $query->whereRaw('1 = 0');
        }
        return $query->whereExists(function ($q) use ($teams) {
            $q->select(DB::raw(1))
                ->from('member_teams')
                ->whereColumn('member_teams.member_id', 'members.id')
                ->whereIn('member_teams.team', $teams);
        });
    }

    /**
     * Members who have at least one team assignment of any kind. Used
     * by the team-progression rollups to drop members with no team set.
     */
    public function scopeHasAnyTeam(Builder $query): Builder
    {
        return $query->whereExists(function ($q) {
            $q->select(DB::raw(1))
                ->from('member_teams')
                ->whereColumn('member_teams.member_id', 'members.id');
        });
    }

    /**
     * Display precedence when a member belongs to several teams: the
     * "main" team wins over its trial sibling, and Mythic outranks
     * Heroic. Drives primaryTeam() and the static groupByTeam() helper.
     */
    private const TEAM_PRIORITY = [
        TeamMapping::TEAM_MYTHIC => 4,
        TeamMapping::TEAM_MYTHIC_TRIAL => 3,
        TeamMapping::TEAM_HEROIC => 2,
        TeamMapping::TEAM_HEROIC_TRIAL => 1,
    ];

    /**
     * The list of team values this member belongs to (rank-derived +
     * override rows, both treated as effective since the resolver
     * guarantees only one set is present at a time). Order is
     * deterministic by precedence so views/tests don't flake.
     *
     * @return list<string>
     */
    public function teamValues(): array
    {
        $teams = $this->teams->pluck('team')->all();
        usort($teams, fn ($a, $b) => (self::TEAM_PRIORITY[$b] ?? 0) <=> (self::TEAM_PRIORITY[$a] ?? 0));
        return array_values($teams);
    }

    public function hasTeam(string $team): bool
    {
        return in_array($team, $this->teamValues(), true);
    }

    /**
     * The single most-important team for back-compat displays (e.g.
     * the character header label). Null if the member has no teams.
     */
    public function primaryTeam(): ?string
    {
        return $this->teamValues()[0] ?? null;
    }

    /**
     * True iff any of this member's teams was set as an override. Drives
     * the admin override panel's "currently overridden" state.
     */
    public function hasTeamOverride(): bool
    {
        return $this->teams->contains(fn (MemberTeam $t) => (bool) $t->is_override);
    }

    /**
     * Bucket a collection of members by team, expanding any member that
     * sits on more than one team into each of their team's buckets.
     * Replaces the old `->groupBy('team')` calls now that members can
     * have multiple rows.
     *
     * @param  Collection<int, Member>  $members
     * @return Collection<string, Collection<int, Member>>
     */
    public static function groupByTeam(Collection $members): Collection
    {
        $out = collect();
        foreach ($members as $member) {
            foreach ($member->teamValues() as $team) {
                if (! $out->has($team)) {
                    $out[$team] = collect();
                }
                $out[$team]->push($member);
            }
        }
        return $out;
    }

    /**
     * Display-cased class name. GRM stores the class as a single uppercase
     * token ("DEATHKNIGHT", "DEMONHUNTER", "ROGUE"). The roster, character
     * page and any human-facing surface should call this instead of
     * lowercasing the raw value.
     */
    public function getClassDisplayAttribute(): ?string
    {
        $raw = $this->class;
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        return match (strtoupper($raw)) {
            'DEATHKNIGHT' => 'Death Knight',
            'DEMONHUNTER' => 'Demon Hunter',
            default => ucfirst(strtolower($raw)),
        };
    }
}
