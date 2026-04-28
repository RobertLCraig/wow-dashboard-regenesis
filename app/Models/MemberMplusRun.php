<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One M+ run completed by a member, sourced from any of Raider.IO's
 * run-bearing fields (recent, weekly best, previous weekly best,
 * season best, alternate) or - eventually - the in-game logger
 * addon. See docs/planning/mplus-run-tracker-addon.md for the addon
 * upgrade path; the schema is shaped so addon rows drop in with
 * source='addon' and the existing dedupe key folds duplicates.
 *
 * Source enum values are documented here as constants for the
 * importer + UI to share. The string-typed `source` column lets us
 * extend without a migration.
 */
class MemberMplusRun extends Model
{
    public const SOURCE_RECENT = 'recent';
    public const SOURCE_WEEKLY_BEST = 'weekly_best';
    public const SOURCE_PREV_WEEKLY_BEST = 'prev_weekly_best';
    public const SOURCE_SEASON_BEST = 'season_best';
    public const SOURCE_ALTERNATE = 'alternate';
    public const SOURCE_ADDON = 'addon';

    protected $table = 'member_mplus_runs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'completed_at' => 'immutable_datetime',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'mythic_level' => 'int',
            'dungeon_id' => 'int',
            'clear_time_ms' => 'int',
            'par_time_ms' => 'int',
            'num_keystone_upgrades' => 'int',
            'score' => 'float',
            'affixes' => 'array',
            'raw_json' => 'array',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function firstSeenSnapshot(): BelongsTo
    {
        return $this->belongsTo(Snapshot::class, 'first_seen_snapshot_id');
    }

    /**
     * "Was the key beaten on time?" - num_keystone_upgrades > 0 covers
     * +1, +2, +3. Untimed completions (overtime) come back as 0.
     */
    public function isTimed(): bool
    {
        return $this->num_keystone_upgrades > 0;
    }

    /** @param  Builder<self>  $query */
    public function scopeBetween(Builder $query, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $query->whereBetween('completed_at', [$from, $to]);
    }

    /** @param  Builder<self>  $query */
    public function scopeForMember(Builder $query, int $memberId): Builder
    {
        return $query->where('member_id', $memberId);
    }
}
