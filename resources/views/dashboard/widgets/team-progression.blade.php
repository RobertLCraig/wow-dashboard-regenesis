@php
    /**
     * Per-team rollup of the latest Raider.IO snapshot. One panel per
     * team that has at least one active member. Empty teams are dropped
     * upstream by the controller.
     *
     * Member counts come from the GRM-derived members.team column;
     * ilvl/RIO/raid stats come from the latest raiderio MemberSnapshot.
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
            on gear or RIO before it becomes a problem on raid night. Team membership
            comes from the GRM rank-to-team mapping under Team mapping; RIO numbers come
            from the periodic raider.io sync.
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
                </div>
            @endforeach
        </div>
    @endif
</section>
