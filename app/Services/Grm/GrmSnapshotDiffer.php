<?php

namespace App\Services\Grm;

use App\Models\Member;
use App\Models\MemberEvent;
use App\Models\MemberSnapshot;
use App\Models\Snapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Compare the just-ingested snapshot N to its predecessor N-1 (same
 * guild_key + same source) and emit member_events rows for every signal
 * the dashboard cares about.
 *
 * Detection rules are documented in luminous-moseying-bear.md and
 * mirrored in the table below the constants.
 */
class GrmSnapshotDiffer
{
    public function __construct(
        private readonly string $guildKey,
        private readonly int $inactiveDays = 30,
    ) {}

    /**
     * @return array<string,int>  Counts per event type emitted.
     */
    public function diff(Snapshot $current): array
    {
        $previous = Snapshot::query()
            ->where('guild_key', $this->guildKey)
            ->where('source', $current->source)
            ->where('id', '<', $current->id)
            ->orderByDesc('id')
            ->first();

        $emitted = [];
        $emit = function (Member $m, string $type, array $payload = null, CarbonImmutable $when = null) use ($current, &$emitted) {
            MemberEvent::query()->create([
                'member_id' => $m->id,
                'snapshot_id' => $current->id,
                'type' => $type,
                'payload_json' => $payload,
                'occurred_at' => $when ?? CarbonImmutable::now(),
            ]);
            $emitted[$type] = ($emitted[$type] ?? 0) + 1;
        };

        $now = CarbonImmutable::now();

        // ── Anniversaries (synthetic, run every ingest) ────────────────
        // Crossed any 365d boundary since (a) the previous snapshot's time
        // or (b) this member's last anniversary event - whichever is later.
        $sinceCutoff = $previous?->captured_at ?? CarbonImmutable::now()->subDay();
        Member::query()
            ->where('guild_key', $this->guildKey)
            ->whereNotNull('join_date')
            ->where('join_date_unknown', false)
            ->where('status', Member::STATUS_ACTIVE)
            ->chunkById(200, function ($members) use ($emit, $sinceCutoff, $now) {
                foreach ($members as $m) {
                    $join = CarbonImmutable::parse($m->join_date);
                    $thisYearAnniv = $join->copy()->setYear($now->year);
                    if ($thisYearAnniv->greaterThan($now)) {
                        $thisYearAnniv = $thisYearAnniv->subYear();
                    }
                    if ($thisYearAnniv->greaterThan($sinceCutoff) && $thisYearAnniv->lessThanOrEqualTo($now)) {
                        $years = (int) $join->diffInYears($thisYearAnniv);
                        if ($years > 0) {
                            // Don't double-emit if we already wrote one
                            // for the same member at the same date.
                            $exists = MemberEvent::query()
                                ->where('member_id', $m->id)
                                ->where('type', MemberEvent::TYPE_ANNIVERSARY)
                                ->whereDate('occurred_at', $thisYearAnniv->toDateString())
                                ->exists();
                            if (! $exists) {
                                $emit($m, MemberEvent::TYPE_ANNIVERSARY, ['years' => $years], $thisYearAnniv);
                            }
                        }
                    }
                }
            });

        // ── Became inactive (synthetic, run every ingest) ──────────────
        // Crossed the inactive_days boundary since the previous snapshot.
        $threshold = $now->subDays($this->inactiveDays);
        Member::query()
            ->where('guild_key', $this->guildKey)
            ->where('status', Member::STATUS_ACTIVE)
            ->whereNotNull('last_online_at')
            ->where('last_online_at', '<', $threshold)
            ->chunkById(200, function ($members) use ($emit, $previous, $threshold) {
                foreach ($members as $m) {
                    // Was the member active in the previous snapshot? If
                    // yes (or no previous), this is a fresh transition.
                    $wasActive = $previous
                        ? MemberSnapshot::query()
                            ->where('snapshot_id', $previous->id)
                            ->where('member_id', $m->id)
                            ->whereNotNull('last_online_at')
                            ->where('last_online_at', '>=', $threshold)
                            ->exists()
                        : true;
                    if (! $wasActive) {
                        continue;
                    }
                    // Don't re-emit if we already wrote one in the last 24h.
                    $recent = MemberEvent::query()
                        ->where('member_id', $m->id)
                        ->where('type', MemberEvent::TYPE_BECAME_INACTIVE_30D)
                        ->where('occurred_at', '>=', now()->subDay())
                        ->exists();
                    if (! $recent) {
                        $emit($m, MemberEvent::TYPE_BECAME_INACTIVE_30D);
                    }
                }
            });

        if (! $previous) {
            // First-ever snapshot for this guild: every active member is a
            // new join. Skipped on subsequent snapshots.
            Member::query()
                ->where('guild_key', $this->guildKey)
                ->where('status', Member::STATUS_ACTIVE)
                ->chunkById(200, function ($members) use ($emit) {
                    foreach ($members as $m) {
                        $emit($m, MemberEvent::TYPE_JOINED, ['initial' => true]);
                    }
                });
            return $emitted;
        }

        // Build a fast lookup of every member_id present in the previous
        // snapshot's per-member rows. Anything in N that's NOT in N-1 is a
        // join; anything in N-1 that's NOT in N is a leave.
        $previousMemberIds = MemberSnapshot::query()
            ->where('snapshot_id', $previous->id)
            ->pluck('member_id')
            ->all();
        $currentMemberIds = MemberSnapshot::query()
            ->where('snapshot_id', $current->id)
            ->pluck('member_id')
            ->all();

        $prevSet = array_flip($previousMemberIds);
        $currSet = array_flip($currentMemberIds);

        $joinedIds = array_diff_key($currSet, $prevSet);
        $leftIds = array_diff_key($prevSet, $currSet);

        // Joins: split into joined vs returned based on whether the
        // member has a prior 'left' or 'kicked' event.
        if (! empty($joinedIds)) {
            Member::query()->whereIn('id', array_keys($joinedIds))->chunk(100, function ($joiners) use ($emit) {
                foreach ($joiners as $m) {
                    $hadLeft = MemberEvent::query()
                        ->where('member_id', $m->id)
                        ->whereIn('type', [MemberEvent::TYPE_LEFT, MemberEvent::TYPE_KICKED, MemberEvent::TYPE_BANNED])
                        ->exists();
                    $emit($m, $hadLeft ? MemberEvent::TYPE_RETURNED : MemberEvent::TYPE_JOINED);
                }
            });
        }

        // Leaves: distinguish kicked/banned from voluntary by looking at
        // the member's current status (set by the normalizer based on
        // PlayersThatLeftHistory.bannedInfo).
        if (! empty($leftIds)) {
            Member::query()->whereIn('id', array_keys($leftIds))->chunk(100, function ($leavers) use ($emit) {
                foreach ($leavers as $m) {
                    $type = match ($m->status) {
                        Member::STATUS_BANNED => MemberEvent::TYPE_BANNED,
                        default => MemberEvent::TYPE_LEFT,
                    };
                    $emit($m, $type);
                }
            });
        }

        // For members present in BOTH snapshots, compare per-member fields.
        $sharedIds = array_intersect_key($currSet, $prevSet);
        if (! empty($sharedIds)) {
            $prevByMember = MemberSnapshot::query()
                ->where('snapshot_id', $previous->id)
                ->whereIn('member_id', array_keys($sharedIds))
                ->get()
                ->keyBy('member_id');
            $currByMember = MemberSnapshot::query()
                ->where('snapshot_id', $current->id)
                ->whereIn('member_id', array_keys($sharedIds))
                ->get()
                ->keyBy('member_id');

            $members = Member::query()->whereIn('id', array_keys($sharedIds))->get()->keyBy('id');

            foreach ($sharedIds as $id => $_) {
                $prev = $prevByMember->get($id);
                $curr = $currByMember->get($id);
                $member = $members->get($id);
                if (! $prev || ! $curr || ! $member) {
                    continue;
                }
                $this->diffMemberSnapshots($member, $prev, $curr, $emit);
            }
        }

        return $emitted;
    }

    private function diffMemberSnapshots(Member $member, MemberSnapshot $prev, MemberSnapshot $curr, callable $emit): void
    {
        // Rank changes: GRM stores rank_index where 0 is the highest
        // (Guild Master). A LOWER index means a promotion.
        if ($prev->rank_index !== null && $curr->rank_index !== null && $prev->rank_index !== $curr->rank_index) {
            $type = $curr->rank_index < $prev->rank_index
                ? MemberEvent::TYPE_PROMOTED
                : MemberEvent::TYPE_DEMOTED;
            $emit($member, $type, [
                'from_rank_index' => $prev->rank_index,
                'to_rank_index' => $curr->rank_index,
                'to_rank_name' => $member->rank_name,
            ]);
        }

        if ($prev->level !== null && $curr->level !== null && $curr->level > $prev->level) {
            $emit($member, MemberEvent::TYPE_LEVEL_UP, [
                'from' => $prev->level,
                'to' => $curr->level,
            ]);
        }

        // Recommend flags: emit one event per flag flipped from false to
        // true. Flips back to false don't emit (clean state).
        foreach ([
            MemberEvent::TYPE_MARKED_FOR_PROMOTE => ['recommend_promote'],
            MemberEvent::TYPE_MARKED_FOR_DEMOTE => ['recommend_demote'],
            MemberEvent::TYPE_MARKED_FOR_KICK => ['recommend_kick'],
        ] as $type => [$field]) {
            if (! $prev->{$field} && $curr->{$field}) {
                $emit($member, $type);
            }
        }

        // Note changes: compare any of the three note fields. We only
        // store the new value in payload (the old is in member_snapshots).
        $prevRaw = $prev->raw_json ?? [];
        $currRaw = $curr->raw_json ?? [];
        $changes = [];
        foreach ([
            'public' => 'note',
            'officer' => 'officerNote',
            'custom' => 'customNote.3',
        ] as $kind => $path) {
            $prevVal = data_get($prevRaw, $path);
            $currVal = data_get($currRaw, $path);
            if ($prevVal !== $currVal) {
                $changes[$kind] = ['from' => $prevVal, 'to' => $currVal];
            }
        }
        if ($changes) {
            $emit($member, MemberEvent::TYPE_NOTE_CHANGED, $changes);
        }
    }
}
