@php
    /**
     * Team progression widget. Three stacked layers, each one zooming
     * in: a four-chip summary, a teams-as-columns comparison table,
     * and a raid block where each difficulty groups its team-progress
     * rows side by side. Boss names render full-width, no truncation,
     * because that's the data officers want to scan.
     *
     * Member counts come from the GRM-derived members.team mapping,
     * ilvl/RIO/raid stats come from the latest raiderio MemberSnapshot,
     * and the per-team boss kill rows come from MemberRaidSnapshot
     * filtered to the current tier.
     */

    $teamTone = function (string $team): string {
        return match ($team) {
            \App\Models\TeamMapping::TEAM_MYTHIC       => 'text-amber-300',
            \App\Models\TeamMapping::TEAM_MYTHIC_TRIAL => 'text-amber-200/80',
            \App\Models\TeamMapping::TEAM_HEROIC       => 'text-emerald-300',
            \App\Models\TeamMapping::TEAM_HEROIC_TRIAL => 'text-emerald-200/80',
            default => 'text-ink',
        };
    };

    $teamBarColor = function (string $team): string {
        return match ($team) {
            \App\Models\TeamMapping::TEAM_MYTHIC       => 'bg-amber-400',
            \App\Models\TeamMapping::TEAM_MYTHIC_TRIAL => 'bg-amber-500/70',
            \App\Models\TeamMapping::TEAM_HEROIC       => 'bg-emerald-400',
            \App\Models\TeamMapping::TEAM_HEROIC_TRIAL => 'bg-emerald-500/70',
            default => 'bg-accent',
        };
    };

    $diffBadgeClass = function (string $type): string {
        return match ($type) {
            'MYTHIC' => 'border-amber-500/60 bg-amber-500/10 text-amber-200',
            'HEROIC' => 'border-emerald-500/60 bg-emerald-500/10 text-emerald-200',
            'NORMAL' => 'border-sky-500/60 bg-sky-500/10 text-sky-200',
            default  => 'border-line bg-panel text-ink',
        };
    };

    $diffSummaryColor = function (string $type): string {
        return match ($type) {
            'MYTHIC' => 'text-amber-300',
            'HEROIC' => 'text-emerald-300',
            'NORMAL' => 'text-sky-300',
            default  => 'text-muted',
        };
    };

    $bossChipClass = function (string $type, bool $killed): string {
        if (! $killed) {
            return 'border-line/60 bg-panel/40 text-muted';
        }
        return match ($type) {
            'MYTHIC' => 'border-amber-500/70 bg-amber-500/15 text-amber-200',
            'HEROIC' => 'border-emerald-500/70 bg-emerald-500/15 text-emerald-200',
            'NORMAL' => 'border-sky-500/70 bg-sky-500/15 text-sky-200',
            default  => 'border-line bg-panel text-ink',
        };
    };

    $teamColumns = $teamProgression['teams'];
    $teamCount = count($teamColumns);
    $tierName = $teamProgression['current_tier']['instance_name'] ?? null;
    $tierExpansion = $teamProgression['current_tier']['expansion_name'] ?? null;
@endphp

<section class="bg-panel border border-line rounded-lg overflow-hidden">
    <div x-data="{ explain: false }">
        <header class="px-4 py-3 border-b border-line flex items-start justify-between gap-3 flex-wrap">
            <div class="min-w-0">
                <h2 class="text-sm font-semibold uppercase tracking-wider flex items-center gap-2 flex-wrap">
                    <span>Team progression</span>
                    @if ($tierName)
                        <span class="text-xs font-normal normal-case tracking-normal text-muted">
                            {{ $tierName }}@if ($tierExpansion) <span class="text-muted/70">/ {{ $tierExpansion }}</span>@endif
                        </span>
                    @endif
                    <x-explainer-toggle />
                </h2>
            </div>
            <div class="text-xs text-muted text-right shrink-0 leading-tight space-y-0.5">
                @if ($teamProgression['captured_at'])
                    <div>raider.io {{ $teamProgression['captured_at']->diffForHumans() }}</div>
                @else
                    <div>no raider.io data yet</div>
                @endif
                @if (! empty($teamProgression['breakdown_captured_at']))
                    <div>blizzard {{ $teamProgression['breakdown_captured_at']->diffForHumans() }}</div>
                @endif
            </div>
        </header>
        <x-explainer-panel title="Team progression">
            Three layers stacked from broadest to most detailed: a four-chip summary
            (teams, raiders, top Mythic kills, top Heroic kills); a comparison table
            with one column per team and the shared metrics as rows (members, average
            item level, top mythic+ score, top weekly key, best raid summary); and a
            raid-by-raid breakdown of {{ $tierName ?? 'the current tier' }}, where each
            difficulty groups its teams' progress rows side by side with a progress bar
            plus the full boss list. Difficulty caps still apply per team (Heroic team
            rows never appear under the Mythic difficulty section, even if a member
            crossed over). Older tiers are hidden so the panel stays focused on the
            active season. Team membership comes from the GRM rank-to-team mapping
            under Team mapping; RIO numbers come from the periodic raider.io sync.
        </x-explainer-panel>
    </div>

    @if (empty($teamColumns))
        <div class="p-8 text-center text-muted text-sm">
            No members have a team assigned yet.
            <a href="{{ route('admin.teams.index') }}" class="text-accent hover:underline">Configure team mapping</a>
            and re-run the GRM sync.
        </div>
    @else
        @php
            $summary = $teamProgression['summary'];
            $topM = $summary['top_kills']['MYTHIC'] ?? null;
            $topH = $summary['top_kills']['HEROIC'] ?? null;
        @endphp

        <div class="px-4 pt-4 pb-3 grid grid-cols-2 md:grid-cols-4 gap-2 text-center text-xs clarity-keep-grid">
            <div class="rounded border border-line bg-panel/60 px-2 py-1.5">
                <div class="text-base font-semibold text-ink">{{ $summary['team_count'] }}</div>
                <div class="text-[10px] uppercase tracking-wider text-muted">{{ \Illuminate\Support\Str::plural('team', $summary['team_count']) }}</div>
            </div>
            <div class="rounded border border-line bg-panel/60 px-2 py-1.5">
                <div class="text-base font-semibold text-ink">{{ $summary['raider_count'] }}</div>
                <div class="text-[10px] uppercase tracking-wider text-muted">raiders</div>
            </div>
            <div class="rounded border border-amber-700/50 bg-amber-950/20 px-2 py-1.5">
                <div class="text-base font-semibold {{ $diffSummaryColor('MYTHIC') }} font-mono">
                    @if ($topM)
                        {{ $topM['killed'] }}/{{ $topM['total'] }}
                    @else
                        <span class="text-muted">-</span>
                    @endif
                </div>
                <div class="text-[10px] uppercase tracking-wider text-muted">top mythic</div>
            </div>
            <div class="rounded border border-emerald-700/50 bg-emerald-950/20 px-2 py-1.5">
                <div class="text-base font-semibold {{ $diffSummaryColor('HEROIC') }} font-mono">
                    @if ($topH)
                        {{ $topH['killed'] }}/{{ $topH['total'] }}
                    @else
                        <span class="text-muted">-</span>
                    @endif
                </div>
                <div class="text-[10px] uppercase tracking-wider text-muted">top heroic</div>
            </div>
        </div>

        <div class="px-4 pb-4">
            <div class="overflow-x-auto -mx-4 px-4">
                <table class="min-w-full text-sm border-separate border-spacing-0">
                    <thead>
                        <tr class="text-xs uppercase tracking-wider text-muted">
                            <th scope="col" class="text-left font-medium py-2 pr-3 border-b border-line align-bottom">Metric</th>
                            @foreach ($teamColumns as $teamKey => $stats)
                                <th scope="col" class="text-left font-semibold py-2 px-3 border-b border-line align-bottom whitespace-nowrap {{ $teamTone($teamKey) }}">
                                    {{ $stats['label'] }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="font-mono">
                        <tr>
                            <th scope="row" class="text-left font-normal text-xs uppercase tracking-wider text-muted py-2 pr-3 border-b border-line/40">Members</th>
                            @foreach ($teamColumns as $teamKey => $stats)
                                <td class="py-2 px-3 border-b border-line/40 whitespace-nowrap">
                                    {{ $stats['count'] }}
                                    @if ($stats['with_data'] < $stats['count'])
                                        <span class="text-[10px] font-sans text-muted">({{ $stats['with_data'] }} on RIO)</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                        <tr>
                            <th scope="row" class="text-left font-normal text-xs uppercase tracking-wider text-muted py-2 pr-3 border-b border-line/40">Avg ilvl</th>
                            @foreach ($teamColumns as $stats)
                                <td class="py-2 px-3 border-b border-line/40 whitespace-nowrap">{{ $stats['avg_ilvl'] ?? '-' }}</td>
                            @endforeach
                        </tr>
                        <tr>
                            <th scope="row" class="text-left font-normal text-xs uppercase tracking-wider text-muted py-2 pr-3 border-b border-line/40">Top RIO</th>
                            @foreach ($teamColumns as $stats)
                                <td class="py-2 px-3 border-b border-line/40 whitespace-nowrap">
                                    {{ $stats['top_rio'] !== null ? number_format($stats['top_rio'], 0) : '-' }}
                                </td>
                            @endforeach
                        </tr>
                        <tr>
                            <th scope="row" class="text-left font-normal text-xs uppercase tracking-wider text-muted py-2 pr-3 border-b border-line/40">Top weekly key</th>
                            @foreach ($teamColumns as $stats)
                                <td class="py-2 px-3 border-b border-line/40 whitespace-nowrap">
                                    {{ $stats['top_key'] !== null ? '+' . $stats['top_key'] : '-' }}
                                </td>
                            @endforeach
                        </tr>
                        <tr>
                            <th scope="row" class="text-left font-normal text-xs uppercase tracking-wider text-muted py-2 pr-3">Best raid</th>
                            @foreach ($teamColumns as $stats)
                                <td class="py-2 px-3 whitespace-nowrap">
                                    {{ $stats['best_raid_summary'] ?? '-' }}
                                </td>
                            @endforeach
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        @if (! empty($teamProgression['raids']))
            <div class="px-4 pb-4 space-y-4 border-t border-line pt-4">
                @foreach ($teamProgression['raids'] as $raid)
                    <article class="space-y-3">
                        <h3 class="text-sm font-semibold text-ink">{{ $raid['name'] ?: 'Current raid' }}</h3>

                        @foreach ($raid['difficulties'] as $diff)
                            <section class="rounded-md border border-line/60 bg-panel/40 p-3 space-y-2.5">
                                <header class="flex items-center gap-2">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded border text-[11px] font-semibold uppercase tracking-wider {{ $diffBadgeClass($diff['type']) }}">
                                        {{ $diff['label'] }}
                                    </span>
                                </header>

                                <ul class="space-y-2.5 list-none m-0 p-0">
                                    @foreach ($diff['team_rows'] as $row)
                                        <li class="m-0 p-0">
                                            <div class="flex items-center gap-3 text-xs">
                                                <div class="w-28 shrink-0 truncate font-medium {{ $teamTone($row['team']) }}">
                                                    {{ $row['team_label'] }}
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <div class="h-2 rounded-full bg-line/40 overflow-hidden" role="progressbar"
                                                         aria-valuenow="{{ $row['killed'] }}"
                                                         aria-valuemin="0"
                                                         aria-valuemax="{{ $row['total'] }}"
                                                         aria-label="{{ $row['team_label'] }} {{ $diff['label'] }} progress">
                                                        <div class="h-full {{ $teamBarColor($row['team']) }}" style="width: {{ $row['pct'] }}%"></div>
                                                    </div>
                                                </div>
                                                <div class="font-mono w-16 text-right shrink-0 {{ $diffSummaryColor($diff['type']) }}">
                                                    {{ $row['killed'] }}/{{ $row['total'] }}
                                                </div>
                                            </div>

                                            @if (! empty($row['encounters']))
                                                <ul class="mt-1.5 ml-28 pl-3 flex flex-wrap gap-1 list-none m-0 p-0">
                                                    @foreach ($row['encounters'] as $enc)
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
                                                                class="inline-flex items-center h-5 px-2 rounded border text-[10px] font-medium leading-none whitespace-nowrap {{ $bossChipClass($diff['type'], $killed) }}"
                                                                title="{{ $title }}"
                                                                aria-label="{{ $title }}"
                                                            >
                                                                {{ $enc['name'] !== '' ? $enc['name'] : '#' . $enc['id'] }}
                                                            </span>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </section>
                        @endforeach
                    </article>
                @endforeach

                @if (! empty($teamProgression['insights']))
                    <aside class="rounded-md border border-line/60 bg-panel/40 px-3 py-2.5">
                        <h4 class="text-[11px] uppercase tracking-wider text-muted mb-1.5">Insights</h4>
                        <ul class="text-xs text-ink space-y-1 list-disc pl-4 m-0">
                            @foreach ($teamProgression['insights'] as $line)
                                <li>{{ $line }}</li>
                            @endforeach
                        </ul>
                    </aside>
                @endif
            </div>
        @elseif (empty($teamProgression['raids']))
            <div class="px-4 pb-4 border-t border-line pt-4">
                <p class="text-[11px] text-muted italic">
                    No Blizzard raid-encounters data for the current tier yet. The daily
                    blizzard:pull-raids sync populates the boss-by-boss breakdown.
                </p>
            </div>
        @endif
    @endif
</section>
