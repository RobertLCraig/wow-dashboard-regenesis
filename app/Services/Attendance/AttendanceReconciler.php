<?php

namespace App\Services\Attendance;

use App\Models\Member;
use App\Models\RaidEvent;
use App\Models\WclActorParse;
use App\Models\WclReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * For each recent past raid event, reconcile Raid-Helper signups
 * against WCL parses to surface the gap that officers actually care
 * about: who said they were coming and didn't actually zone in.
 *
 * Matching is by the first segment of the character name, lower-cased
 * (matchKey()). Same convention CompositionController uses, since the
 * Raid-Helper template sometimes drops the realm suffix.
 *
 * Alt-cohort aware: a signup whose own character isn't in the WCL
 * parse list still counts as showing up if any other member of their
 * alt group is. The widget shows that as "showed via {altname}" so
 * raid leads can see when a player swapped specs.
 */
class AttendanceReconciler
{
    /** Statuses on event_signups that mean "I'm not coming" - excluded from the signup pool. */
    private const STATUSES_EXCLUDE = ['absence', 'absent', 'bench', 'declined', 'dps_bench', 'declined_late', 'tentative'];

    /** Window (hours) to match a WCL report's start_time to an event's starts_at. */
    private const MATCH_WINDOW_HOURS = 6;

    /**
     * @return Collection<int, array{
     *     event: RaidEvent,
     *     wcl_report: ?WclReport,
     *     signed_up_count: int,
     *     showed_up_count: int,
     *     no_shows: list<array{name:string,class:?string,role:?string}>,
     *     showed_via_alts: list<array{signup_name:string,alt_name:string}>,
     * }>
     */
    public function recent(string $guildKey, int $days = 14, int $limit = 5): Collection
    {
        $since = CarbonImmutable::now()->subDays($days);
        $events = RaidEvent::query()
            ->where('starts_at', '>=', $since)
            ->where('starts_at', '<', now())
            ->orderByDesc('starts_at')
            ->limit($limit)
            ->get();

        if ($events->isEmpty()) {
            return collect();
        }

        // Pre-fetch all members for the guild once. Match-key lookups
        // and alt-cohort resolution all run against this in-memory map,
        // so reconcile() per event stays loop-free on the DB side.
        $allMembers = Member::query()
            ->forGuild($guildKey)
            ->get(['id', 'name', 'alt_group_id']);
        $membersByKey = $allMembers->keyBy(fn (Member $m) => $this->matchKey($m->name));
        $cohortKeysByMemberId = $this->buildCohortKeyMap($allMembers);

        return $events->map(fn (RaidEvent $event) => $this->reconcile(
            $event,
            $guildKey,
            $membersByKey,
            $cohortKeysByMemberId,
        ));
    }

    /**
     * @param  Collection<string, Member>  $membersByKey
     * @param  Collection<int, list<string>>  $cohortKeysByMemberId
     * @return array{
     *     event: RaidEvent,
     *     wcl_report: ?WclReport,
     *     signed_up_count: int,
     *     showed_up_count: int,
     *     no_shows: list<array{name:string,class:?string,role:?string}>,
     *     showed_via_alts: list<array{signup_name:string,alt_name:string}>,
     * }
     */
    private function reconcile(
        RaidEvent $event,
        string $guildKey,
        Collection $membersByKey,
        Collection $cohortKeysByMemberId,
    ): array {
        $signups = $event->signups()
            ->whereNotIn('status', self::STATUSES_EXCLUDE)
            ->where('is_fake', false)
            ->get();

        $wclReport = $this->findMatchingReport($event, $guildKey);

        if ($wclReport === null) {
            return [
                'event' => $event,
                'wcl_report' => null,
                'signed_up_count' => $signups->count(),
                'showed_up_count' => 0,
                'no_shows' => [],
                'showed_via_alts' => [],
            ];
        }

        $actorKeys = $this->actorKeysFor($wclReport);

        $noShows = [];
        $showedViaAlts = [];
        $showedUp = 0;
        foreach ($signups as $s) {
            $key = $this->matchKey($s->name);

            if (isset($actorKeys[$key])) {
                $showedUp++;
                continue;
            }

            // Direct miss: did any of their alts show?
            $member = $membersByKey->get($key);
            if ($member) {
                $cohortKeys = $cohortKeysByMemberId->get($member->id, []);
                foreach ($cohortKeys as $altKey) {
                    if ($altKey !== $key && isset($actorKeys[$altKey])) {
                        $showedUp++;
                        $showedViaAlts[] = [
                            'signup_name' => $s->name,
                            'alt_name' => $actorKeys[$altKey],
                        ];
                        continue 2;
                    }
                }
            }

            $noShows[] = [
                'name' => $s->name,
                'class' => $s->class_name,
                'role' => $s->role,
            ];
        }

        return [
            'event' => $event,
            'wcl_report' => $wclReport,
            'signed_up_count' => $signups->count(),
            'showed_up_count' => $showedUp,
            'no_shows' => $noShows,
            'showed_via_alts' => $showedViaAlts,
        ];
    }

    /**
     * Match strategy: WCL report whose start_time is closest to the
     * event's starts_at within +/- MATCH_WINDOW_HOURS hours. Sorted in
     * PHP rather than the DB so SQLite + MySQL behave the same.
     */
    private function findMatchingReport(RaidEvent $event, string $guildKey): ?WclReport
    {
        $start = $event->starts_at;
        $windowStart = $start->copy()->subHours(self::MATCH_WINDOW_HOURS);
        $windowEnd = $start->copy()->addHours(self::MATCH_WINDOW_HOURS);

        $candidates = WclReport::query()
            ->where('guild_key', $guildKey)
            ->whereBetween('start_time', [$windowStart, $windowEnd])
            ->whereNotNull('fights_imported_at')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        return $candidates
            ->sortBy(fn (WclReport $r) => abs($r->start_time->diffInSeconds($start)))
            ->first();
    }

    /**
     * Distinct actor names in the report, keyed by lower-cased match key
     * with the original-cased name as the value (so the "showed via alt"
     * note can render the proper character casing back).
     *
     * @return array<string,string>
     */
    private function actorKeysFor(WclReport $report): array
    {
        $names = WclActorParse::query()
            ->whereHas('fight', fn ($q) => $q->where('wcl_report_id', $report->id))
            ->distinct()
            ->pluck('actor_name');

        $keys = [];
        foreach ($names as $n) {
            $key = $this->matchKey((string) $n);
            if ($key !== '' && ! isset($keys[$key])) {
                $keys[$key] = (string) $n;
            }
        }
        return $keys;
    }

    /**
     * For each member, build the list of match keys for their alt
     * cohort (the member themselves + any siblings sharing alt_group_id).
     * Keyed by member_id so reconcile() can look up cohort keys cheaply.
     *
     * @param  Collection<int, Member>  $allMembers
     * @return Collection<int, list<string>>
     */
    private function buildCohortKeyMap(Collection $allMembers): Collection
    {
        $byGroup = $allMembers
            ->filter(fn (Member $m) => $m->alt_group_id !== null)
            ->groupBy('alt_group_id');

        return $allMembers->mapWithKeys(function (Member $m) use ($byGroup) {
            if ($m->alt_group_id !== null) {
                $cohort = $byGroup->get($m->alt_group_id, collect([$m]));
            } else {
                $cohort = collect([$m]);
            }
            return [$m->id => $cohort->pluck('name')->map(fn ($n) => $this->matchKey((string) $n))->values()->all()];
        });
    }

    private function matchKey(string $name): string
    {
        return strtolower(explode('-', trim($name), 2)[0]);
    }
}
