<?php

namespace App\Services\Digest;

use App\Models\Member;
use App\Models\MemberAction;
use App\Models\MemberEvent;
use App\Models\MemberSnapshot;
use App\Models\Snapshot;
use App\Models\TeamMapping;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Synthesises the officer-facing weekly summary from data the dashboard
 * already has. No new tables, no new external calls.
 *
 * The output is a Markdown document under Discord's 2000-char per-message
 * limit (the poster handles splitting if it ever overflows). Sections are
 * kept short on purpose - it's a "what to look at this week" digest, not
 * an exhaustive report. Officers click into the dashboard for detail.
 */
class WeeklyDigestBuilder
{
    public function __construct(
        private readonly string $guildKey,
        /** Reference "now" for the week window. Injectable so tests can pin time. */
        private readonly ?CarbonImmutable $now = null,
    ) {}

    /**
     * @return array{markdown: string, data: array<string,mixed>}
     */
    public function build(): array
    {
        $now = $this->now ?? CarbonImmutable::now();
        $weekAgo = $now->subDays(7);

        $data = [
            'period_label' => "{$weekAgo->format('D d M')} - {$now->format('D d M Y')}",
            'roster' => $this->rosterDelta($weekAgo, $now),
            'anniversaries' => $this->anniversaries($now),
            'newly_inactive' => $this->newlyInactive($weekAgo, $now),
            'action_queue' => $this->actionQueueCounts(),
            'team_progression' => $this->teamProgression(),
            'top_rio' => $this->topRio(5),
        ];

        return ['markdown' => $this->renderMarkdown($data), 'data' => $data];
    }

    /**
     * @return array{active:int, joined:int, left:int, delta:int}
     */
    private function rosterDelta(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $active = Member::active()->forGuild($this->guildKey)->count();

        $joined = MemberEvent::query()
            ->whereHas('member', fn ($q) => $q->forGuild($this->guildKey))
            ->whereIn('type', [MemberEvent::TYPE_JOINED, MemberEvent::TYPE_RETURNED])
            ->whereBetween('occurred_at', [$from, $to])
            ->count();

        $left = MemberEvent::query()
            ->whereHas('member', fn ($q) => $q->forGuild($this->guildKey))
            ->whereIn('type', [MemberEvent::TYPE_LEFT, MemberEvent::TYPE_KICKED, MemberEvent::TYPE_BANNED])
            ->whereBetween('occurred_at', [$from, $to])
            ->count();

        return [
            'active' => $active,
            'joined' => $joined,
            'left' => $left,
            'delta' => $joined - $left,
        ];
    }

    /**
     * @return Collection<int, array{name: string, years: int}>
     */
    private function anniversaries(CarbonImmutable $now): Collection
    {
        $weekStart = $now->startOfWeek();
        $weekEnd = $weekStart->endOfWeek();

        return MemberEvent::query()
            ->where('type', MemberEvent::TYPE_ANNIVERSARY)
            ->whereBetween('occurred_at', [$weekStart, $weekEnd])
            ->whereHas('member', fn ($q) => $q->forGuild($this->guildKey))
            ->with('member:id,name,join_date')
            ->orderBy('occurred_at')
            ->get()
            ->map(function (MemberEvent $e) use ($now) {
                $years = $e->member?->join_date
                    ? (int) $e->member->join_date->diffInYears($now)
                    : 0;
                return ['name' => $e->member?->name ?? '?', 'years' => $years];
            });
    }

    /**
     * Members whose last_online_at falls within the past 7 days *of*
     * crossing a 30/60/90-day boundary. Cheap heuristic: members whose
     * last_online_at is between (now - threshold - 7d) and (now - threshold).
     *
     * @return Collection<int, array{name: string, threshold: int, days_ago: int}>
     */
    private function newlyInactive(CarbonImmutable $weekAgo, CarbonImmutable $now): Collection
    {
        $rows = collect();
        foreach ([30, 60, 90] as $threshold) {
            $upper = $now->subDays($threshold);
            $lower = $upper->subDays(7);
            $hits = Member::active()->forGuild($this->guildKey)
                ->whereBetween('last_online_at', [$lower, $upper])
                ->limit(20)
                ->get(['name', 'last_online_at'])
                ->map(fn (Member $m) => [
                    'name' => $m->name,
                    'threshold' => $threshold,
                    'days_ago' => (int) round($m->last_online_at->diffInDays($now)),
                ]);
            $rows = $rows->concat($hits);
        }
        return $rows->sortByDesc('days_ago')->values();
    }

    /**
     * @return array{promote:int, demote:int, kick:int}
     */
    private function actionQueueCounts(): array
    {
        $reviewed = MemberAction::query()
            ->whereIn('decision', [MemberAction::DECISION_ACCEPTED, MemberAction::DECISION_DISMISSED])
            ->orWhere(function (QueryBuilder|\Illuminate\Database\Eloquent\Builder $q) {
                $q->where('decision', MemberAction::DECISION_SNOOZED)
                  ->where('snooze_until', '>', $this->now ?? now());
            })
            ->pluck('action_type', 'member_id');

        $count = function (string $col, string $type) use ($reviewed) {
            return Member::active()->forGuild($this->guildKey)
                ->where($col, true)
                ->get(['id'])
                ->reject(fn ($m) => ($reviewed[$m->id] ?? null) === $type)
                ->count();
        };

        return [
            'promote' => $count('recommend_promote', MemberAction::TYPE_PROMOTE),
            'demote'  => $count('recommend_demote',  MemberAction::TYPE_DEMOTE),
            'kick'    => $count('recommend_kick',    MemberAction::TYPE_KICK),
        ];
    }

    /**
     * @return array<string, array{count:int, best_summary:?string, top_ilvl:?int}>
     */
    private function teamProgression(): array
    {
        $latest = $this->latestRaiderio();
        if (! $latest) return [];

        $snapsByMember = MemberSnapshot::query()
            ->where('snapshot_id', $latest->id)
            ->get()
            ->keyBy('member_id');

        $membersByTeam = Member::active()->forGuild($this->guildKey)
            ->whereNotNull('team')
            ->get()
            ->groupBy('team');

        $out = [];
        foreach (TeamMapping::TEAMS as $team) {
            $members = $membersByTeam->get($team, collect());
            if ($members->isEmpty()) continue;

            $snaps = $members->map(fn ($m) => $snapsByMember->get($m->id))->filter();

            $bestSummary = null;
            $bestM = -1;
            $bestH = -1;
            foreach ($snaps as $snap) {
                foreach ((array) ($snap->raid_progression_json ?? []) as $p) {
                    if (! is_array($p)) continue;
                    $m = (int) ($p['mythic_bosses_killed'] ?? 0);
                    $h = (int) ($p['heroic_bosses_killed'] ?? 0);
                    if ($m > $bestM || ($m === $bestM && $h > $bestH)) {
                        $bestM = $m;
                        $bestH = $h;
                        $bestSummary = is_string($p['summary'] ?? null) ? $p['summary'] : null;
                    }
                }
            }
            $ilvls = $snaps->pluck('ilvl')->filter()->all();

            $out[$team] = [
                'count' => $members->count(),
                'best_summary' => $bestSummary,
                'top_ilvl' => $ilvls ? max($ilvls) : null,
            ];
        }
        return $out;
    }

    /**
     * @return Collection<int, array{name: string, score: float, key: ?int}>
     */
    private function topRio(int $limit): Collection
    {
        $latest = $this->latestRaiderio();
        if (! $latest) return collect();

        return MemberSnapshot::query()
            ->where('snapshot_id', $latest->id)
            ->whereNotNull('mplus_score')
            ->orderByDesc('mplus_score')
            ->limit($limit)
            ->with('member:id,name,status')
            ->get()
            ->filter(fn ($s) => $s->member?->status === Member::STATUS_ACTIVE)
            ->map(fn ($s) => [
                'name' => $s->member->name,
                'score' => (float) $s->mplus_score,
                'key' => $s->mplus_keystone,
            ])
            ->values();
    }

    private function latestRaiderio(): ?Snapshot
    {
        return Snapshot::query()
            ->where('guild_key', $this->guildKey)
            ->where('source', Snapshot::SOURCE_RAIDERIO)
            ->latest('captured_at')
            ->first();
    }

    /**
     * @param  array<string,mixed>  $d
     */
    private function renderMarkdown(array $d): string
    {
        $lines = [];
        $lines[] = "**Regenesis weekly digest** - {$d['period_label']}";
        $lines[] = '';

        $r = $d['roster'];
        $deltaSign = $r['delta'] >= 0 ? '+' : '';
        $lines[] = "**Roster**: {$r['active']} active. This week: +{$r['joined']} / -{$r['left']} ({$deltaSign}{$r['delta']}).";

        $aq = $d['action_queue'];
        if ($aq['promote'] || $aq['demote'] || $aq['kick']) {
            $lines[] = "**Action queue**: {$aq['promote']} promote, {$aq['demote']} demote, {$aq['kick']} kick.";
        }

        if (! empty($d['team_progression'])) {
            $lines[] = '';
            $lines[] = '__Team progression__';
            foreach ($d['team_progression'] as $team => $stats) {
                $label = TeamMapping::teamLabel($team);
                $bits = ["{$stats['count']} members"];
                if ($stats['best_summary']) $bits[] = $stats['best_summary'];
                if ($stats['top_ilvl'])     $bits[] = "top ilvl {$stats['top_ilvl']}";
                $lines[] = "- {$label}: " . implode(' / ', $bits);
            }
        }

        if ($d['top_rio']->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '__Top M+ scores__';
            foreach ($d['top_rio'] as $i => $row) {
                $key = $row['key'] !== null ? " (+{$row['key']})" : '';
                $score = number_format($row['score'], 0);
                $rank = $i + 1;
                $lines[] = "{$rank}. {$row['name']} - {$score}{$key}";
            }
        }

        if ($d['anniversaries']->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '__Anniversaries this week__';
            foreach ($d['anniversaries'] as $row) {
                $years = $row['years'] > 0 ? " ({$row['years']}y)" : '';
                $lines[] = "- {$row['name']}{$years}";
            }
        }

        if ($d['newly_inactive']->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '__Newly inactive (just crossed 30 / 60 / 90d)__';
            foreach ($d['newly_inactive']->take(10) as $row) {
                $lines[] = "- {$row['name']} - {$row['days_ago']}d";
            }
        }

        return implode("\n", $lines);
    }
}
