@php
    /**
     * Per-team rollup of the latest Raider.IO snapshot, plus a per-boss
     * breakdown sourced from the latest Blizzard raid-encounters
     * snapshot. One panel per team that has at least one active
     * member. Empty teams are dropped upstream by the controller.
     *
     * Member counts come from the GRM-derived members.team column;
     * ilvl/RIO/raid stats come from the latest raiderio MemberSnapshot;
     * the boss-by-boss pip rows come from MemberRaidSnapshot.expansions.
     */
    $tone = function (string $team) {
        return match ($team) {
            \App\Models\TeamMapping::TEAM_MYTHIC       => 'border-amber-700/50 bg-amber-950/20',
            \App\Models\TeamMapping::TEAM_MYTHIC_TRIAL => 'border-amber-700/30 bg-amber-950/10',
            \App\Models\TeamMapping::TEAM_HEROIC       => 'border-emerald-700/50 bg-emerald-950/20',
            \App\Models\TeamMapping::TEAM_HEROIC_TRIAL => 'border-emerald-700/30 bg-emerald-950/10',
            default => 'border-line bg-panel',
        };
    };

    $instanceLabel = function (?string $key): string {
        if (! $key) return '';
        // raider.io returns slugs like "manaforge-omega"; titlecase them.
        return ucwords(str_replace(['-', '_'], ' ', $key));
    };

    // Difficulty-keyed pip styles: filled = team-killed, empty = not yet.
    $diffPipClass = function (string $type, bool $killed): string {
        if (! $killed) {
            return 'border-line/60 bg-panel/40 text-muted';
        }
        return match ($type) {
            'MYTHIC' => 'border-amber-500/70 bg-amber-500/20 text-amber-200',
            'HEROIC' => 'border-emerald-500/70 bg-emerald-500/20 text-emerald-200',
            'NORMAL' => 'border-sky-500/70 bg-sky-500/20 text-sky-200',
            default  => 'border-line bg-panel text-ink',
        };
    };
    $diffBadgeClass = function (string $type): string {
        return match ($type) {
            'MYTHIC' => 'text-amber-300',
            'HEROIC' => 'text-emerald-300',
            'NORMAL' => 'text-sky-300',
            default  => 'text-muted',
        };
    };
@endphp

<section class="bg-panel border border-line rounded-lg overflow-hidden">
    <div x-data="{ explain: false }">
        <header class="px-4 py-3 border-b border-line flex items-center justify-between">
            <h2 class="text-sm font-semibold uppercase tracking-wider flex items-center gap-2">
                <span>Team progression</span>
                <x-explainer-toggle />
            </h2>
            <span class="text-xs text-muted">
                @if ($teamProgression['captured_at'])
                    raider.io {{ $teamProgression['captured_at']->diffForHumans() }}
                @else
                    no raider.io data yet
                @endif
            </span>
        </header>
        <x-explainer-panel title="Team progression">
            Per-team rollup of the latest Raider.IO snapshot. Best raid progression,
            average item level, top mythic+ score and top weekly key for each team
            (Mythic, Mythic Trial, Heroic, Heroic Trial). Use it to compare how teams
            are pacing through the current tier and to spot a team that's fallen behind
            on gear or RIO before it becomes a problem on raid night. The boss-by-boss
            breakdown below the headline numbers comes from the daily Blizzard raid
            encounters pull: each pip is an encounter in that raid, filled when at
            least one team member has the kill on that difficulty. Difficulty is
            capped per team (Heroic team panels never show Mythic, even if a member
            crossed over). Team membership comes from the GRM rank-to-team mapping
            under Team mapping; RIO numbers come from the periodic raider.io sync.
        </x-explainer-panel>
    </div>

    @if (empty($teamProgression['teams']))
        <div class="p-8 text-center text-muted text-sm">
            No members have a team assigned yet.
            <a href="{{ route('admin.teams.index') }}" class="text-accent hover:underline">Configure team mapping</a>
            and re-run the GRM sync.
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 p-3 clarity-keep-grid">
            @foreach ($teamProgression['teams'] as $team => $stats)
                <div class="rounded-md border {{ $tone($team) }} p-4">
                    <div class="flex items-baseline justify-between">
                        <h3 class="font-semibold text-ink">
                            {{ \App\Models\TeamMapping::teamLabel($team) }}
                            <span class="text-muted text-sm font-normal">
                                {{ $stats['count'] }} {{ \Illuminate\Support\Str::plural('member', $stats['count']) }}
                            </span>
                        </h3>
                        @if ($stats['with_data'] < $stats['count'])
                            <span class="text-xs text-muted">
                                {{ $stats['with_data'] }}/{{ $stats['count'] }} on RIO
                            </span>
                        @endif
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-2 text-sm clarity-keep-grid">
                        <div>
                            <div class="text-xs uppercase tracking-wider text-muted">Best progression</div>
                            <div class="font-mono mt-0.5">
                                @if ($stats['best_raid_summary'])
                                    {{ $stats['best_raid_summary'] }}
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </div>
                            @if ($stats['best_raid_key'])
                                <div class="text-xs text-muted mt-0.5">{{ $instanceLabel($stats['best_raid_key']) }}</div>
                            @endif
                        </div>

                        <div>
                            <div class="text-xs uppercase tracking-wider text-muted">Avg ilvl</div>
                            <div class="font-mono mt-0.5">
                                {{ $stats['avg_ilvl'] ?? '-' }}
                            </div>
                        </div>

                        <div>
                            <div class="text-xs uppercase tracking-wider text-muted">Top RIO</div>
                            <div class="font-mono mt-0.5">
                                {{ $stats['top_rio'] !== null ? number_format($stats['top_rio'], 0) : '-' }}
                            </div>
                        </div>

                        <div>
                            <div class="text-xs uppercase tracking-wider text-muted">Top weekly key</div>
                            <div class="font-mono mt-0.5">
                                {{ $stats['top_key'] !== null ? '+' . $stats['top_key'] : '-' }}
                            </div>
                        </div>
                    </div>

                    @if (! empty($stats['breakdown']))
                        <div class="mt-4 pt-3 border-t border-line/60 space-y-3">
                            <div class="flex items-baseline justify-between">
                                <h4 class="text-xs uppercase tracking-wider text-muted">Boss breakdown</h4>
                                @if (! empty($stats['breakdown_captured_at']))
                                    <span class="text-[10px] text-muted">
                                        blizzard {{ $stats['breakdown_captured_at']->diffForHumans() }}
                                    </span>
                                @endif
                            </div>
                            @foreach ($stats['breakdown'] as $instance)
                                <div>
                                    <div class="text-sm font-medium text-ink">{{ $instance['name'] ?: 'Raid' }}</div>
                                    <div class="mt-1.5 space-y-1.5">
                                        @foreach ($instance['difficulties'] as $diff)
                                            <div class="flex items-center gap-2 text-xs">
                                                <span class="font-mono w-12 shrink-0 {{ $diffBadgeClass($diff['type']) }}">
                                                    {{ $diff['killed'] }}/{{ $diff['total'] }} {{ $diff['short'] }}
                                                </span>
                                                <ul class="flex flex-wrap gap-1 list-none m-0 p-0">
                                                    @foreach ($diff['encounters'] as $enc)
                                                        @php
                                                            $killed = $enc['killers'] > 0;
                                                            $title = $enc['name'];
                                                            if ($killed) {
                                                                $title .= ' - killed by ' . $enc['killers']
                                                                    . ' team ' . \Illuminate\Support\Str::plural('member', $enc['killers']);
                                                                if (! empty($enc['last_kill_ms'])) {
                                                                    $title .= ' - last ' . \Carbon\CarbonImmutable::createFromTimestampMs($enc['last_kill_ms'])->diffForHumans();
                                                                }
                                                            } else {
                                                                $title .= ' - not yet down';
                                                            }
                                                        @endphp
                                                        <li class="m-0 p-0">
                                                            <span
                                                                class="inline-flex items-center justify-center min-w-[1.5rem] h-5 px-1 rounded border text-[10px] font-medium leading-none {{ $diffPipClass($diff['type'], $killed) }}"
                                                                title="{{ $title }}"
                                                                aria-label="{{ $title }}"
                                                            >
                                                                {{ $enc['name'] !== '' ? \Illuminate\Support\Str::limit($enc['name'], 14, '...') : '#' . $enc['id'] }}
                                                            </span>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @elseif (empty($stats['breakdown']) && $stats['count'] > 0)
                        <div class="mt-4 pt-3 border-t border-line/60">
                            <p class="text-[11px] text-muted italic">
                                No Blizzard raid-encounters data for this team yet. The daily
                                blizzard:pull-raids sync populates the boss-by-boss breakdown.
                            </p>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</section>
