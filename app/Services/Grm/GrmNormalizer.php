<?php

namespace App\Services\Grm;

use App\Models\AltGroup;
use App\Models\LogEvent;
use App\Models\Member;
use App\Models\MemberSnapshot;
use App\Models\Snapshot;
use App\Services\Teams\TeamResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Take the JSON payload the PC's sync tool uploaded - already parsed from
 * Lua to nested arrays - and write it into our normalized schema:
 *
 *   members + member_snapshots + alt_groups + alt_group_members + log_events
 *
 * Snapshot row is created BEFORE this runs (in the controller) so we can
 * dispatch the heavy work as a queue job. The differ runs AFTER this,
 * comparing the just-ingested snapshot to the previous one to emit
 * member_events signals.
 */
class GrmNormalizer
{
    public function __construct(
        private readonly string $guildKey,
        private readonly string $timezone = 'Europe/London',
        private readonly ?TeamResolver $teamResolver = null,
    ) {}

    private function teams(): TeamResolver
    {
        return $this->teamResolver ?? app(TeamResolver::class);
    }

    /**
     * Apply a parsed GRM payload to the DB. Returns counts for logging.
     *
     * @param  array<string,mixed>  $payload  Output of LuaTableParser
     * @return array{members:int, log_events:int, alt_groups:int}
     */
    public function apply(Snapshot $snapshot, array $payload): array
    {
        $now = CarbonImmutable::now();

        $current = $payload['GRM_GuildMemberHistory_Save'][$this->guildKey] ?? [];
        $former = $payload['GRM_PlayersThatLeftHistory_Save'][$this->guildKey] ?? [];
        $alts = $payload['GRM_Alts'][$this->guildKey] ?? [];
        $log = $payload['GRM_LogReport_Save'][$this->guildKey] ?? [];

        $memberCount = 0;
        $logCount = 0;
        $altGroupCount = 0;

        // Wrap the bulk work in one transaction so a partial failure
        // doesn't leave the DB in an inconsistent state. The diff job
        // re-uses the same snapshot row.
        DB::transaction(function () use (
            $snapshot, $current, $former, $alts, $log, $now,
            &$memberCount, &$logCount, &$altGroupCount,
        ) {
            // Pass 1: every player we know about (current OR former).
            // We process current first so a player who appears in both
            // (rare but possible during the transition) gets the "active"
            // status from the current pass.
            foreach ($former as $name => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $this->upsertMember($snapshot, $name, $row, isFormer: true, now: $now);
                $memberCount++;
            }
            foreach ($current as $name => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $this->upsertMember($snapshot, $name, $row, isFormer: false, now: $now);
                $memberCount++;
            }

            // Pass 2: alt groups + main_member_id stitching. Has to come
            // after pass 1 because both main and alts must already exist
            // as member rows.
            foreach ($alts as $groupLabel => $group) {
                if (! is_array($group)) {
                    continue;
                }
                $this->upsertAltGroup((string) $groupLabel, $group);
                $altGroupCount++;
            }

            // Pass 3: log entries. Idempotent via dedup_hash so re-ingest
            // of the same snapshot doesn't double-write.
            foreach ($log as $row) {
                if ($this->upsertLog($row)) {
                    $logCount++;
                }
            }
        });

        $snapshot->forceFill(['member_count' => $memberCount])->save();

        return [
            'members' => $memberCount,
            'log_events' => $logCount,
            'alt_groups' => $altGroupCount,
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function upsertMember(Snapshot $snapshot, string $name, array $row, bool $isFormer, CarbonImmutable $now): void
    {
        $capturedAt = $snapshot->captured_at instanceof CarbonImmutable
            ? $snapshot->captured_at
            : CarbonImmutable::parse((string) $snapshot->captured_at);
        $lastOnline = GrmTimeUtil::lastOnlineAt($row, $capturedAt);
        $joinDate = GrmTimeUtil::joinDate($row['joinDateHist'] ?? null);

        $banned = $row['bannedInfo'] ?? [];
        $isBanned = (bool) ($banned[1] ?? false);

        $status = match (true) {
            $isFormer && $isBanned => Member::STATUS_BANNED,
            $isFormer => Member::STATUS_LEFT,
            default => Member::STATUS_ACTIVE,
        };

        // Coerce GRM's `note` into public_note. The dashboard treats
        // public_note + officer_note + custom_note as three separate
        // fields even though GRM's UI labels them slightly differently.
        $publicNote = is_string($row['note'] ?? null) ? $row['note'] : null;
        $officerNote = is_string($row['officerNote'] ?? null) ? $row['officerNote'] : null;
        $customNote = null;
        if (isset($row['customNote']) && is_array($row['customNote'])) {
            $customNote = $row['customNote'][3] ?? $row['customNote'][4] ?? null;
            if (! is_string($customNote)) {
                $customNote = null;
            }
        }

        $reasonBanned = $banned[4] ?? null;
        if (! is_string($reasonBanned) || $reasonBanned === '') {
            $reasonBanned = is_string($row['reasonBanned'] ?? null) ? $row['reasonBanned'] : null;
        }

        $bannedAt = null;
        if ($isBanned && isset($banned[2]) && is_int($banned[2]) && $banned[2] > 0) {
            $bannedAt = CarbonImmutable::createFromTimestampUTC($banned[2]);
        }

        $hcDead = isset($row['HC']['isDead']) ? (bool) $row['HC']['isDead'] : false;

        $prof1 = $row['prof1'] ?? null;
        $prof2 = $row['prof2'] ?? null;

        // GRM uses -1 as "unknown" for ints it hasn't queried yet (e.g.
        // achievementPoints for offline alts). Our int columns are
        // unsigned, so coerce negatives to null instead of letting MySQL
        // reject the row with SQLSTATE 22003.
        $uint = static fn (mixed $v): ?int => is_int($v) && $v >= 0 ? $v : null;

        // firstOrCreate + forceFill is cleaner than updateOrCreate here
        // because first_seen_at must only be set on insert, and there's
        // no per-insert-vs-update value form on updateOrCreate.
        $member = Member::query()->firstOrCreate(
            [
                'guild_key' => $this->guildKey,
                'name' => $name,
            ],
            [
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ]
        );

        $member->forceFill([
            'guid' => $row['GUID'] ?? null,
            'class' => is_string($row['class'] ?? null) ? $row['class'] : null,
            'race' => is_string($row['race'] ?? null) ? $row['race'] : null,
            'level' => $uint($row['level'] ?? null),
            'sex' => is_string($row['sex'] ?? null) ? $row['sex'] : null,
            'faction' => is_string($row['faction'] ?? null) ? $row['faction'] : null,
            'rank_name' => is_string($row['rankName'] ?? null) ? $row['rankName'] : null,
            'rank_index' => $uint($row['rankIndex'] ?? null),
            'join_date' => $joinDate?->toDateString(),
            'join_date_unknown' => (bool) ($row['joinDateUnknown'] ?? false),
            'last_online_at' => $lastOnline,
            'is_online' => (bool) ($row['isOnline'] ?? false),
            'is_mobile' => (bool) ($row['isMobile'] ?? false),
            'status' => $status,
            'achievement_points' => $uint($row['achievementPoints'] ?? null),
            'guild_rep' => $uint($row['guildRep'] ?? null),
            'hardcore_is_dead' => $hcDead,
            'profession_1_id' => is_array($prof1) ? $uint($prof1[1] ?? null) : null,
            'profession_1_skill' => is_array($prof1) ? $uint($prof1[2] ?? null) : null,
            'profession_2_id' => is_array($prof2) ? $uint($prof2[1] ?? null) : null,
            'profession_2_skill' => is_array($prof2) ? $uint($prof2[2] ?? null) : null,
            'alt_group_label' => is_string($row['altGroup'] ?? null) && $row['altGroup'] !== '' ? $row['altGroup'] : null,
            'public_note' => $publicNote,
            'officer_note' => $officerNote,
            'custom_note' => $customNote,
            'zone' => is_string($row['zone'] ?? null) ? $row['zone'] : null,
            'recommend_promote' => (bool) ($row['recommendToPromote'] ?? false),
            'recommend_demote' => (bool) ($row['recommendToDemote'] ?? false),
            'recommend_kick' => (bool) ($row['recommendToKick'] ?? false),
            'recommend_special' => (bool) ($row['recommendSpecial'] ?? false),
            'reason_banned' => $reasonBanned,
            'banned_at' => $bannedAt,
            'last_seen_at' => $now,
        ])->save();

        // Sync the member_teams pivot from the just-written rank_name.
        // The resolver leaves the member alone if an officer has set an
        // override, so manual team assignments survive rank changes.
        $this->teams()->syncRankRowsForMember($member);

        // Per-snapshot row. Carries the volatile fields plus the full
        // per-member raw payload for replay/debugging.
        MemberSnapshot::query()->create([
            'snapshot_id' => $snapshot->id,
            'member_id' => $member->id,
            'level' => $member->level,
            'rank_index' => $member->rank_index,
            'last_online_at' => $member->last_online_at,
            'recommend_promote' => $member->recommend_promote,
            'recommend_demote' => $member->recommend_demote,
            'recommend_kick' => $member->recommend_kick,
            'raw_json' => $row,
        ]);
    }

    /**
     * @param  array<int|string,mixed>  $group
     */
    private function upsertAltGroup(string $groupLabel, array $group): void
    {
        $main = is_string($group['main'] ?? null) ? $group['main'] : null;
        $nickname = $group['nicknameDetails']['nickname'] ?? null;
        $timeModified = isset($group['timeModified']) && is_int($group['timeModified'])
            ? CarbonImmutable::createFromTimestampUTC($group['timeModified'])
            : null;

        $mainMember = $main ? Member::query()
            ->where('guild_key', $this->guildKey)
            ->where('name', $main)
            ->first() : null;

        $altGroup = AltGroup::query()->updateOrCreate(
            [
                'guild_key' => $this->guildKey,
                'group_label' => $groupLabel,
            ],
            [
                'main_member_id' => $mainMember?->id,
                'nickname' => is_string($nickname) ? $nickname : null,
                'time_modified' => $timeModified,
            ]
        );

        // Walk the positional entries (1, 2, 3, ...) for member char/class.
        // GRM also tucks string-keyed metadata (main, timeModified, etc.)
        // into the same table, which we skip here.
        $memberIds = [];
        $isMainMap = [];
        foreach ($group as $key => $entry) {
            if (! is_int($key) || ! is_array($entry)) {
                continue;
            }
            $name = $entry['name'] ?? null;
            if (! is_string($name)) {
                continue;
            }
            $member = Member::query()
                ->where('guild_key', $this->guildKey)
                ->where('name', $name)
                ->first();
            if (! $member) {
                continue;
            }
            $memberIds[] = $member->id;
            $isMainMap[$member->id] = ($main === $name);
            $member->forceFill([
                'alt_group_id' => $altGroup->id,
                'main_member_id' => $main !== null && $main !== $name ? $mainMember?->id : null,
            ])->save();
        }

        // Sync pivot. Uses the [member_id => ['is_main' => bool]] form so
        // belongsToMany#sync correctly carries the pivot column.
        $sync = [];
        foreach ($memberIds as $id) {
            $sync[$id] = ['is_main' => $isMainMap[$id]];
        }
        $altGroup->members()->sync($sync);
    }

    /**
     * Returns true if a new row was created, false if it was a duplicate.
     *
     * @param  array<int|string,mixed>  $row
     */
    private function upsertLog(array $row): bool
    {
        // GRM_LogReport_Save row shape (positional):
        //   [type_code, message, ...args, [day, month, year, hour, minute]]
        // The trailing array varies in length but is always the timestamp.
        $values = array_values($row);
        $typeCode = $values[0] ?? null;
        $message = $values[1] ?? null;
        if (! is_int($typeCode) || ! is_string($message)) {
            return false;
        }

        // Find the trailing timestamp array.
        $timestampArr = null;
        for ($i = count($values) - 1; $i >= 0; $i--) {
            if (is_array($values[$i]) && count($values[$i]) >= 5) {
                $timestampArr = $values[$i];
                break;
            }
        }
        $occurredAt = GrmTimeUtil::logTimestamp($timestampArr, $this->timezone);
        if (! $occurredAt) {
            return false;
        }

        // Actor / target heuristic: most rows put the actor at index 3
        // (after type_code, message, optional bool flag) and target at 4.
        // We let actor/target be null when the row doesn't fit the shape.
        $actor = null;
        $target = null;
        foreach ($values as $i => $v) {
            if ($i < 2 || ! is_string($v)) {
                continue;
            }
            if (str_contains($v, '|c') || str_contains($v, '-')) {
                if ($actor === null) {
                    $actor = $v;
                } elseif ($target === null) {
                    $target = $v;
                    break;
                }
            }
        }

        $dedupHash = hash('sha256', implode('|', [
            $this->guildKey,
            $occurredAt->getTimestamp(),
            $typeCode,
            $message,
        ]));

        // Idempotent insert. updateOrCreate is overkill here since we
        // never want to update; a firstOrCreate keyed on dedup_hash does it.
        $existing = LogEvent::query()->where('dedup_hash', $dedupHash)->first();
        if ($existing) {
            return false;
        }

        LogEvent::query()->create([
            'guild_key' => $this->guildKey,
            'occurred_at' => $occurredAt,
            'type_code' => $typeCode,
            'type_name' => self::logTypeName($typeCode, $values, $message),
            'actor' => $actor,
            'target' => $target,
            'message_raw' => $message,
            'raw_json' => $row,
            'dedup_hash' => $dedupHash,
        ]);

        return true;
    }

    /**
     * Map a GRM log type code to a stable string label. Codes verified
     * against upstream GRM_Log.lua (TheGeneticsGuy/Guild-Roster-Manager
     * Retail branch).
     *
     * Two codes carry sub-types in the row payload:
     *   - 10 (left-or-kicked): boolean playerWasKicked at $values[3].
     *   - 15 (event): we keyword-match the rendered message to split
     *     birthday vs anniversary, since eventIndex semantics aren't
     *     documented and the rendered string is the reliable signal.
     *
     * Unknown codes return null and the timeline widget falls back to
     * the rendered message.
     *
     * @param  array<int,mixed>  $values  array_values() of the saved row
     */
    public static function logTypeName(int $code, array $values = [], string $message = ''): ?string
    {
        return match ($code) {
            1 => 'PROMOTED',
            2 => 'DEMOTED',
            3 => 'LEVEL_UP',
            4 => 'PUBLIC_NOTE',
            5 => 'OFFICER_NOTE',
            6 => 'RANK_RENAME',
            7 => 'REJOINED',
            8 => 'JOINED',
            9 => 'REJOINED_BANNED',
            10 => ! empty($values[3]) ? 'KICKED' : 'LEFT',
            11 => 'NAME_CHANGE',
            14 => 'INACTIVE_RETURN',
            15 => self::eventSubtype($message),
            16 => 'RECOMMEND_KICK',
            22 => 'RECOMMEND_PROMOTE',
            23 => 'RECOMMEND_DEMOTE',
            24 => 'HARDCORE_DEATH',
            25 => 'RECOMMEND_SPECIAL',
            default => null,
        };
    }

    private static function eventSubtype(string $message): string
    {
        $lower = strtolower($message);
        if (str_contains($lower, 'anniversary') || str_contains($lower, 'in the guild')) {
            return 'EVENT_ANNIVERSARY';
        }
        if (str_contains($lower, 'birthday')) {
            return 'EVENT_BIRTHDAY';
        }

        return 'EVENT';
    }
}
