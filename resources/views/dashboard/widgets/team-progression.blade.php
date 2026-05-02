@php
    $teamColumns   = $teamProgression['teams'];
    $tierName      = $teamProgression['current_tier']['instance_name'] ?? null;
    $tierExpansion = $teamProgression['current_tier']['expansion_name'] ?? null;
@endphp
@php
    /**
     * Team progression widget.
     *  1. Summary chips
     *  2. Metrics comparison table
     *  3. Kill matrix - two modes toggled via Alpine (persisted in localStorage):
     *       matrix: GitHub-style filled squares, two axis orientations
     *       detail: full clarity tables with per-diff progress bars
     *
     * Colour language:
     *   Difficulty: violet = Mythic, amber = Heroic, slate = Normal
     *   Team:       rose   = Mythic/Trial, cyan   = Heroic/Trial
     */

    $diffBadgeClass = fn (string $t): string => match ($t) {
        'MYTHIC' => 'border-violet-500/60 bg-violet-500/10 text-violet-200',
        'HEROIC' => 'border-amber-500/60  bg-amber-500/10  text-amber-200',
        'NORMAL' => 'border-slate-500/60  bg-slate-500/10  text-slate-300',
        default  => 'border-line bg-panel text-ink',
    };

    $diffBarColor = fn (string $t): string => match ($t) {
        'MYTHIC' => 'bg-violet-400',
        'HEROIC' => 'bg-amber-400',
        'NORMAL' => 'bg-slate-400',
        default  => 'bg-accent',
    };

    $diffKillColor = fn (string $t): string => match ($t) {
        'MYTHIC' => 'text-violet-300',
        'HEROIC' => 'text-amber-300',
        'NORMAL' => 'text-slate-300',
        default  => 'text-muted',
    };

    $diffSquareFill = fn (string $t): string => match ($t) {
        'MYTHIC' => 'bg-violet-500',
        'HEROIC' => 'bg-amber-500',
        'NORMAL' => 'bg-slate-500',
        default  => 'bg-accent',
    };

    $diffSquareEmpty = fn (string $t): string => match ($t) {
        'MYTHIC' => 'border border-violet-500/40 bg-violet-500/5',
        'HEROIC' => 'border border-amber-500/40  bg-amber-500/5',
        'NORMAL' => 'border border-slate-500/40  bg-slate-500/5',
        default  => 'border border-line/40',
    };

    $teamTone = fn (string $team): string => match ($team) {
        \App\Models\TeamMapping::TEAM_MYTHIC       => 'text-rose-300',
        \App\Models\TeamMapping::TEAM_MYTHIC_TRIAL => 'text-rose-200/70',
        \App\Models\TeamMapping::TEAM_HEROIC       => 'text-cyan-300',
        \App\Models\TeamMapping::TEAM_HEROIC_TRIAL => 'text-cyan-200/70',
        default => 'text-ink',
    };

    $teamBg = fn (string $team): string => match ($team) {
        \App\Models\TeamMapping::TEAM_MYTHIC,
        \App\Models\TeamMapping::TEAM_MYTHIC_TRIAL => 'bg-rose-500/5',
        \App\Models\TeamMapping::TEAM_HEROIC,
        \App\Models\TeamMapping::TEAM_HEROIC_TRIAL => 'bg-cyan-500/5',
        default => '',
    };

    $teamStripe = fn (string $team): string => match ($team) {
        \App\Models\TeamMapping::TEAM_MYTHIC,
        \App\Models\TeamMapping::TEAM_MYTHIC_TRIAL => 'border-l-2 border-rose-500/40',
        \App\Models\TeamMapping::TEAM_HEROIC,
        \App\Models\TeamMapping::TEAM_HEROIC_TRIAL => 'border-l-2 border-cyan-500/40',
        default => 'border-l border-line/20',
    };

    /**
     * Pivot a single difficulty's team_rows for the detail view.
     */
    $pivotEncounters = function (array $teamRows): array {
        $teams  = [];
        $bosses = [];
        foreach ($teamRows as $row) {
            $teams[$row['team']] = [
                'team'       => $row['team'],
                'team_label' => $row['team_label'],
                'killed'     => $row['killed'],
                'total'      => $row['total'],
                'pct'        => $row['pct'],
            ];
            foreach ($row['encounters'] as $enc) {
                $bosses[$enc['id']] ??= [
                    'id'    => $enc['id'],
                    'name'  => $enc['name'] !== '' ? $enc['name'] : '#' . $enc['id'],
                    'kills' => [],
                ];
                $bosses[$enc['id']]['kills'][$row['team']] = [
                    'killed'       => $enc['killers'] > 0,
                    'killers'      => $enc['killers'],
                    'last_kill_ms' => $enc['last_kill_ms'] ?? null,
                ];
            }
        }
        return ['teams' => array_values($teams), 'bosses' => array_values($bosses)];
    };

    /**
     * Pivot all difficulties for a raid into the compact matrix structure.
     * Cells are keyed [team][diff] - null means team participates but hasn't killed.
     * Missing summary key means team doesn't raid that difficulty at all.
     */
    $pivotMatrix = function (array $difficulties) use ($teamColumns): array {
        $allTeams  = array_keys($teamColumns);
        $allDiffs  = ['MYTHIC', 'HEROIC', 'NORMAL'];
        $bosses    = [];
        $summary   = [];
        $seenDiffs = [];
        $seenTeams = [];

        foreach ($difficulties as $diff) {
            $dType = $diff['type'];
            if (! in_array($dType, $allDiffs, true)) {
                continue;
            }
            $seenDiffs[$dType] = true;

            foreach ($diff['team_rows'] as $row) {
                $team = $row['team'];
                $seenTeams[$team] = true;
                $summary[$team][$dType] = [
                    'killed' => $row['killed'],
                    'total'  => $row['total'],
                ];

                foreach ($row['encounters'] as $enc) {
                    $bosses[$enc['id']] ??= [
                        'id'    => $enc['id'],
                        'name'  => $enc['name'] !== '' ? $enc['name'] : '#' . $enc['id'],
                        'cells' => [],
                    ];
                    $bosses[$enc['id']]['cells'][$team][$dType] = [
                        'killed'       => $enc['killers'] > 0,
                        'killers'      => $enc['killers'],
                        'last_kill_ms' => $enc['last_kill_ms'] ?? null,
                    ];
                }
            }
        }

        return [
            'teams'   => array_values(array_filter($allTeams, fn ($t) => isset($seenTeams[$t]))),
            'diffs'   => array_values(array_filter($allDiffs, fn ($d) => isset($seenDiffs[$d]))),
            'bosses'  => array_values($bosses),
            'summary' => $summary,
        ];
    };

@endphp

<section
    x-data="{
        explain: false,
        mode:   localStorage.getItem('tp_mode')   || 'matrix',
        orient: localStorage.getItem('tp_orient') || 'boss-rows',
        expanded: {},
        setMode(m)   { this.mode   = m; localStorage.setItem('tp_mode',   m); },
        setOrient(o) { this.orient = o; localStorage.setItem('tp_orient', o); },
    }"
    class="bg-panel border border-line rounded-lg overflow-hidden">

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
        <div class="flex items-center gap-3 shrink-0 flex-wrap justify-end">
            {{-- View mode toggle --}}
            <div class="flex rounded border border-line text-xs overflow-hidden">
                <button type="button"
                        @click="setMode('matrix')"
                        :class="mode === 'matrix' ? 'bg-accent/15 text-accent' : 'text-muted hover:text-ink'"
                        class="px-2.5 py-1 transition-colors">
                    Matrix
                </button>
                <button type="button"
                        @click="setMode('detail')"
                        :class="mode === 'detail' ? 'bg-accent/15 text-accent' : 'text-muted hover:text-ink'"
                        class="px-2.5 py-1 border-l border-line transition-colors">
                    Detail
                </button>
            </div>
            {{-- Timestamps --}}
            <div class="text-xs text-muted text-right leading-tight space-y-0.5">
                @if ($teamProgression['captured_at'])
                    <div>raider.io {{ $teamProgression['captured_at']->diffForHumans() }}</div>
                @else
                    <div class="italic">no raider.io data yet</div>
                @endif
                @if (! empty($teamProgression['breakdown_captured_at']))
                    <div>blizzard {{ $teamProgression['breakdown_captured_at']->diffForHumans() }}</div>
                @endif
            </div>
        </div>
    </header>

    <x-explainer-panel title="Team progression">
        Summary chips count active raiders and best kills per difficulty. The metrics table
        compares teams on gear, M+ score, and key level.
        <strong>Matrix view:</strong> filled square = killed, outlined = not yet, grey = team
        doesn't raid that difficulty. Click a boss name to expand kill details. Toggle the axis
        to swap bosses and team/difficulty between rows and columns.
        <strong>Detail view:</strong> full tables per difficulty with progress bars.
        Colours: violet = Mythic, amber = Heroic, slate = Normal. Rose = Mythic team, cyan = Heroic team.
    </x-explainer-panel>

    @if (empty($teamColumns))
        <div class="p-8 text-center text-muted text-sm">
            No members have a team assigned yet.
            <a href="{{ route('admin.teams.index') }}" class="text-accent hover:underline">Configure team mapping</a>
            and re-run the GRM sync.
        </div>
    @else
        @php
            $summary = $teamProgression['summary'];
            $topM    = $summary['top_kills']['MYTHIC'] ?? null;
            $topH    = $summary['top_kills']['HEROIC'] ?? null;
        @endphp

        {{-- Summary chips --}}
        <div class="px-4 pt-4 pb-3 grid grid-cols-2 md:grid-cols-4 gap-2 text-center text-xs clarity-keep-grid">
            <div class="rounded border border-line bg-panel/60 px-2 py-1.5">
                <div class="text-base font-semibold text-ink">{{ $summary['team_count'] }}</div>
                <div class="text-xs uppercase tracking-wider text-muted">{{ \Illuminate\Support\Str::plural('team', $summary['team_count']) }}</div>
            </div>
            <div class="rounded border border-line bg-panel/60 px-2 py-1.5">
                <div class="text-base font-semibold text-ink">{{ $summary['raider_count'] }}</div>
                <div class="text-xs uppercase tracking-wider text-muted">raiders</div>
            </div>
            <div class="rounded border border-violet-700/50 bg-violet-950/20 px-2 py-1.5">
                <div class="text-base font-semibold {{ $diffKillColor('MYTHIC') }} font-mono">
                    {{ $topM ? $topM['killed'] . '/' . $topM['total'] : '-' }}
                </div>
                <div class="text-xs uppercase tracking-wider text-muted">top mythic</div>
            </div>
            <div class="rounded border border-amber-700/50 bg-amber-950/20 px-2 py-1.5">
                <div class="text-base font-semibold {{ $diffKillColor('HEROIC') }} font-mono">
                    {{ $topH ? $topH['killed'] . '/' . $topH['total'] : '-' }}
                </div>
                <div class="text-xs uppercase tracking-wider text-muted">top heroic</div>
            </div>
        </div>

        {{-- Metrics comparison table --}}
        <div class="px-4 pb-4">
            <div class="overflow-x-auto -mx-4 px-4">
                <table class="min-w-full text-sm border-separate border-spacing-0">
                    <thead>
                        <tr class="text-xs uppercase tracking-wider text-muted">
                            <th scope="col" class="text-left font-medium py-2 pr-3 border-b border-line align-bottom w-32">Metric</th>
                            @foreach ($teamColumns as $teamKey => $stats)
                                <th scope="col" class="text-right font-semibold py-2 px-3 border-b border-line align-bottom whitespace-nowrap {{ $teamTone($teamKey) }}">
                                    {{ $stats['label'] }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="font-mono text-right">
                        <tr>
                            <th scope="row" class="text-left font-normal text-xs uppercase tracking-wider text-muted py-2 pr-3 border-b border-line/40">Members</th>
                            @foreach ($teamColumns as $stats)
                                <td class="py-2 px-3 border-b border-line/40 whitespace-nowrap">
                                    {{ $stats['count'] }}
                                    @if ($stats['with_data'] < $stats['count'])
                                        <span class="text-xs font-sans text-muted">({{ $stats['with_data'] }} on RIO)</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                        <tr>
                            <th scope="row" class="text-left font-normal text-xs uppercase tracking-wider text-muted py-2 pr-3 border-b border-line/40">Avg ilvl</th>
                            @foreach ($teamColumns as $stats)
                                <td class="py-2 px-3 border-b border-line/40">{{ $stats['avg_ilvl'] ?? '-' }}</td>
                            @endforeach
                        </tr>
                        <tr>
                            <th scope="row" class="text-left font-normal text-xs uppercase tracking-wider text-muted py-2 pr-3 border-b border-line/40">Top RIO</th>
                            @foreach ($teamColumns as $stats)
                                <td class="py-2 px-3 border-b border-line/40">
                                    {{ $stats['top_rio'] !== null ? number_format($stats['top_rio'], 0) : '-' }}
                                </td>
                            @endforeach
                        </tr>
                        <tr>
                            <th scope="row" class="text-left font-normal text-xs uppercase tracking-wider text-muted py-2 pr-3">Top key</th>
                            @foreach ($teamColumns as $stats)
                                <td class="py-2 px-3">{{ $stats['top_key'] !== null ? '+' . $stats['top_key'] : '-' }}</td>
                            @endforeach
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Kill matrix section --}}
        @if (! empty($teamProgression['raids']))
            <div class="border-t border-line">

                {{-- ═══ Detail view ═══ --}}
                <div x-show="mode === 'detail'">
                    @foreach ($teamProgression['raids'] as $raid)
                        <div class="border-b border-line last:border-0">
                            <div class="px-4 py-1.5 bg-panel/60">
                                <span class="text-xs font-semibold text-muted uppercase tracking-wider">{{ $raid['name'] }}</span>
                            </div>

                            @foreach ($raid['difficulties'] as $diff)
                                @php $grid = $pivotEncounters($diff['team_rows']); @endphp

                                <div class="border-b border-line/30 last:border-0">
                                    <div class="flex items-center gap-4 flex-wrap px-4 py-2.5 bg-panel/40">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded border text-xs font-semibold uppercase tracking-wider shrink-0 {{ $diffBadgeClass($diff['type']) }}">
                                            {{ $diff['label'] }}
                                        </span>
                                        @foreach ($grid['teams'] as $teamRow)
                                            <div class="flex items-center gap-2 min-w-0">
                                                <span class="text-xs font-medium shrink-0 {{ $teamTone($teamRow['team']) }}">
                                                    {{ $teamRow['team_label'] }}
                                                </span>
                                                <div class="w-24 h-1.5 rounded-full bg-line/40 overflow-hidden shrink-0">
                                                    <div class="h-full {{ $diffBarColor($diff['type']) }}" style="width: {{ $teamRow['pct'] }}%"></div>
                                                </div>
                                                <span class="text-xs font-mono {{ $diffKillColor($diff['type']) }} shrink-0">
                                                    {{ $teamRow['killed'] }}/{{ $teamRow['total'] }}
                                                </span>
                                            </div>
                                        @endforeach
                                    </div>

                                    <div class="overflow-x-auto">
                                        <table class="w-full text-xs border-separate border-spacing-0">
                                            <thead>
                                                <tr>
                                                    <th scope="col" class="text-left font-medium text-muted px-4 py-1.5 border-b border-line/30 w-full">Boss</th>
                                                    @foreach ($grid['teams'] as $teamRow)
                                                        <th scope="col" class="text-center font-semibold px-4 py-1.5 border-b border-line/30 whitespace-nowrap {{ $teamTone($teamRow['team']) }}">
                                                            {{ $teamRow['team_label'] }}
                                                        </th>
                                                    @endforeach
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($grid['bosses'] as $boss)
                                                    <tr class="border-b border-line/20 last:border-0 hover:bg-panel/40">
                                                        <td class="px-4 py-1.5 text-ink">{{ $boss['name'] }}</td>
                                                        @foreach ($grid['teams'] as $teamRow)
                                                            @php
                                                                $kill   = $boss['kills'][$teamRow['team']] ?? null;
                                                                $killed = $kill && $kill['killed'];
                                                                $tip    = $boss['name'];
                                                                if ($killed) {
                                                                    $tip .= ' - killed by ' . $kill['killers'] . ' ' . \Illuminate\Support\Str::plural('member', $kill['killers']);
                                                                    if (! empty($kill['last_kill_ms'])) {
                                                                        $tip .= ', last ' . \Carbon\CarbonImmutable::createFromTimestampMs($kill['last_kill_ms'])->diffForHumans();
                                                                    }
                                                                } else {
                                                                    $tip .= ' - not yet down';
                                                                }
                                                            @endphp
                                                            <td class="px-4 py-1.5 text-center" title="{{ $tip }}">
                                                                @if ($killed)
                                                                    <span class="{{ $diffKillColor($diff['type']) }} font-semibold" aria-label="{{ $tip }}">&#10003;</span>
                                                                @else
                                                                    <span class="text-muted/40" aria-label="{{ $tip }}">&#8212;</span>
                                                                @endif
                                                            </td>
                                                        @endforeach
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>

                {{-- ═══ Matrix view ═══ --}}
                <div x-show="mode === 'matrix'">

                    {{-- Axis toggle --}}
                    <div class="px-4 py-2 border-b border-line/40 flex items-center gap-3">
                        <span class="text-xs text-muted uppercase tracking-wider shrink-0">Axis</span>
                        <div class="flex rounded border border-line text-xs overflow-hidden">
                            <button type="button"
                                    @click="setOrient('boss-rows')"
                                    :class="orient === 'boss-rows' ? 'bg-accent/15 text-accent' : 'text-muted hover:text-ink'"
                                    class="px-2.5 py-1 transition-colors">
                                Bosses left
                            </button>
                            <button type="button"
                                    @click="setOrient('boss-cols')"
                                    :class="orient === 'boss-cols' ? 'bg-accent/15 text-accent' : 'text-muted hover:text-ink'"
                                    class="px-2.5 py-1 border-l border-line transition-colors">
                                Bosses top
                            </button>
                        </div>
                    </div>

                    @foreach ($teamProgression['raids'] as $raid)
                        @php $matrix = $pivotMatrix($raid['difficulties']); @endphp

                        <div class="border-b border-line last:border-0">

                            {{-- Raid name + per-team difficulty summary --}}
                            <div class="px-4 py-2 flex items-baseline gap-4 flex-wrap bg-panel/60">
                                <span class="text-xs font-semibold text-muted uppercase tracking-wider shrink-0">
                                    {{ $raid['name'] }}
                                </span>
                                @foreach ($matrix['teams'] as $team)
                                    <div class="flex items-baseline gap-1.5">
                                        <span class="{{ $teamTone($team) }} text-xs font-semibold shrink-0">
                                            {{ $teamColumns[$team]['label'] }}
                                        </span>
                                        @foreach ($matrix['diffs'] as $diff)
                                            @if (isset($matrix['summary'][$team][$diff]))
                                                @php $s = $matrix['summary'][$team][$diff]; @endphp
                                                <span class="{{ $diffKillColor($diff) }} text-xs font-mono">
                                                    {{ strtoupper(substr($diff, 0, 1)) }}{{ $s['killed'] }}/{{ $s['total'] }}
                                                </span>
                                            @endif
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>

                            {{-- ── Mode A: bosses as rows, [team × diff] as columns ── --}}
                            <div x-show="orient === 'boss-rows'" class="overflow-x-auto">
                                <table class="text-xs border-separate border-spacing-0">
                                    <thead>
                                        {{-- Team group headers --}}
                                        <tr>
                                            <th class="text-left px-4 py-1.5 font-normal text-muted border-b border-line/30 min-w-[10rem]"></th>
                                            @foreach ($matrix['teams'] as $team)
                                                <th colspan="{{ count($matrix['diffs']) }}"
                                                    class="text-center py-1.5 border border-line/30 text-xs font-semibold uppercase tracking-wider {{ $teamTone($team) }} {{ $teamBg($team) }}">
                                                    {{ $teamColumns[$team]['label'] }}
                                                </th>
                                            @endforeach
                                        </tr>
                                        {{-- Difficulty labels --}}
                                        <tr>
                                            <th class="px-4 py-1 text-left text-muted font-normal border-b border-line/30 text-xs">Boss</th>
                                            @foreach ($matrix['teams'] as $team)
                                                @foreach ($matrix['diffs'] as $diff)
                                                    <th class="text-center w-10 py-1 border-b border-line/30 {{ $teamBg($team) }} {{ $loop->first ? 'border-l border-line/20' : '' }} {{ $loop->last ? 'border-r border-line/20' : '' }}">
                                                        <span class="text-xs font-semibold {{ $diffKillColor($diff) }}">
                                                            {{ strtoupper(substr($diff, 0, 1)) }}
                                                        </span>
                                                    </th>
                                                @endforeach
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($matrix['bosses'] as $boss)
                                            @php
                                                $totalCols = 1 + count($matrix['teams']) * count($matrix['diffs']);
                                            @endphp
                                            <tr @click="expanded[{{ $boss['id'] }}] = !expanded[{{ $boss['id'] }}]"
                                                class="cursor-pointer hover:bg-panel/50 border-b border-line/20 last:border-0">
                                                <td class="px-4 py-2 text-ink whitespace-nowrap">
                                                    <span class="inline-flex items-center gap-1.5">
                                                        <span x-show="!expanded[{{ $boss['id'] }}]" class="text-xs text-muted/50 leading-none select-none">&#9654;</span>
                                                        <span x-show="expanded[{{ $boss['id'] }}]"  class="text-xs text-muted/50 leading-none select-none">&#9660;</span>
                                                        {{ $boss['name'] }}
                                                    </span>
                                                </td>
                                                @foreach ($matrix['teams'] as $team)
                                                    @foreach ($matrix['diffs'] as $diff)
                                                        @php
                                                            $cell        = $boss['cells'][$team][$diff] ?? null;
                                                            $participates = isset($matrix['summary'][$team][$diff]);
                                                            if (! $participates) {
                                                                $tip = $boss['name'] . ' - ' . $teamColumns[$team]['label'] . ' does not raid ' . strtolower($diff);
                                                            } elseif ($cell && $cell['killed']) {
                                                                $ago = $cell['last_kill_ms']
                                                                    ? \Carbon\CarbonImmutable::createFromTimestampMs($cell['last_kill_ms'])->diffForHumans()
                                                                    : '?';
                                                                $tip = $boss['name'] . ' - ' . $cell['killers'] . ' ' . \Illuminate\Support\Str::plural('raider', $cell['killers']) . ', last ' . $ago;
                                                            } else {
                                                                $tip = $boss['name'] . ' - not killed on ' . strtolower($diff) . ' by ' . $teamColumns[$team]['label'];
                                                            }
                                                        @endphp
                                                        <td class="text-center w-10 py-2 {{ $teamBg($team) }} {{ $loop->first ? 'border-l border-line/20' : '' }} {{ $loop->last ? 'border-r border-line/20' : '' }}" title="{{ $tip }}">
                                                            @if (! $participates)
                                                                <span class="inline-block w-4 h-4 rounded-sm bg-line/15"></span>
                                                            @elseif ($cell && $cell['killed'])
                                                                <span class="inline-block w-4 h-4 rounded-sm {{ $diffSquareFill($diff) }}"></span>
                                                            @else
                                                                <span class="inline-block w-4 h-4 rounded-sm {{ $diffSquareEmpty($diff) }}"></span>
                                                            @endif
                                                        </td>
                                                    @endforeach
                                                @endforeach
                                            </tr>
                                            {{-- Expanded kill detail --}}
                                            <tr x-show="expanded[{{ $boss['id'] }}]" class="bg-panel/30">
                                                <td colspan="{{ $totalCols }}" class="px-8 py-2">
                                                    @php
                                                        $expandParts = [];
                                                        foreach ($matrix['teams'] as $t) {
                                                            $kills = [];
                                                            foreach ($matrix['diffs'] as $d) {
                                                                $c = $boss['cells'][$t][$d] ?? null;
                                                                if ($c && $c['killed']) {
                                                                    $short = strtoupper(substr($d, 0, 1));
                                                                    $ago   = $c['last_kill_ms']
                                                                        ? \Carbon\CarbonImmutable::createFromTimestampMs($c['last_kill_ms'])->diffForHumans()
                                                                        : '?';
                                                                    $kills[] = "{$short}: {$c['killers']} raiders, last {$ago}";
                                                                }
                                                            }
                                                            if ($kills) {
                                                                $expandParts[] = [
                                                                    'team'  => $t,
                                                                    'label' => $teamColumns[$t]['label'],
                                                                    'kills' => $kills,
                                                                ];
                                                            }
                                                        }
                                                    @endphp
                                                    @if (empty($expandParts))
                                                        <span class="text-xs text-muted italic">No kills recorded.</span>
                                                    @else
                                                        <div class="flex flex-wrap gap-x-5 gap-y-0.5 text-xs">
                                                            @foreach ($expandParts as $part)
                                                                <span>
                                                                    <span class="{{ $teamTone($part['team']) }} font-medium">{{ $part['label'] }}:</span>
                                                                    <span class="text-muted">{{ implode(' | ', $part['kills']) }}</span>
                                                                </span>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            {{-- ── Mode B: bosses as columns, [team × diff] as rows ── --}}
                            <div x-show="orient === 'boss-cols'" class="overflow-x-auto">
                                <table class="text-xs border-separate border-spacing-0">
                                    <thead>
                                        <tr>
                                            <th class="text-left px-4 py-1.5 font-normal text-muted border-b border-line/30 whitespace-nowrap">Team / Diff</th>
                                            @foreach ($matrix['bosses'] as $boss)
                                                <th class="text-center px-3 py-1.5 border-b border-line/30 text-muted font-medium whitespace-nowrap">
                                                    {{ $boss['name'] }}
                                                </th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($matrix['teams'] as $team)
                                            @foreach ($matrix['diffs'] as $diff)
                                                @php
                                                    $isFirstDiff    = $loop->first;
                                                    $isFirstTeam    = $loop->parent->first;
                                                    $labelPadClass  = $isFirstDiff && ! $isFirstTeam ? 'pt-3 pb-1.5' : 'py-1.5';
                                                    $cellPadClass   = $isFirstDiff && ! $isFirstTeam ? 'pt-3 pb-1.5' : 'py-1.5';
                                                @endphp
                                                <tr class="border-b border-line/20 last:border-0 hover:bg-panel/30">
                                                    <td class="px-4 {{ $labelPadClass }} whitespace-nowrap {{ $teamStripe($team) }} {{ $teamBg($team) }}">
                                                        <span class="{{ $teamTone($team) }} font-medium">{{ $teamColumns[$team]['label'] }}</span>
                                                        <span class="{{ $diffKillColor($diff) }} ml-1 font-mono">{{ strtoupper(substr($diff, 0, 1)) }}</span>
                                                    </td>
                                                    @foreach ($matrix['bosses'] as $boss)
                                                        @php
                                                            $cell        = $boss['cells'][$team][$diff] ?? null;
                                                            $participates = isset($matrix['summary'][$team][$diff]);
                                                            if (! $participates) {
                                                                $tip = $boss['name'] . ' - ' . $teamColumns[$team]['label'] . ' does not raid ' . strtolower($diff);
                                                            } elseif ($cell && $cell['killed']) {
                                                                $ago = $cell['last_kill_ms']
                                                                    ? \Carbon\CarbonImmutable::createFromTimestampMs($cell['last_kill_ms'])->diffForHumans()
                                                                    : '?';
                                                                $tip = $boss['name'] . ' - ' . $cell['killers'] . ' ' . \Illuminate\Support\Str::plural('raider', $cell['killers']) . ', last ' . $ago;
                                                            } else {
                                                                $tip = $boss['name'] . ' - not killed on ' . strtolower($diff);
                                                            }
                                                        @endphp
                                                        <td class="text-center px-3 {{ $cellPadClass }} {{ $teamBg($team) }}" title="{{ $tip }}">
                                                            @if (! $participates)
                                                                <span class="inline-block w-4 h-4 rounded-sm bg-line/15"></span>
                                                            @elseif ($cell && $cell['killed'])
                                                                <span class="inline-block w-4 h-4 rounded-sm {{ $diffSquareFill($diff) }}"></span>
                                                            @else
                                                                <span class="inline-block w-4 h-4 rounded-sm {{ $diffSquareEmpty($diff) }}"></span>
                                                            @endif
                                                        </td>
                                                    @endforeach
                                                </tr>
                                            @endforeach
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                        </div>
                    @endforeach
                </div>

                {{-- Insights (both modes) --}}
                @if (! empty($teamProgression['insights']))
                    <aside class="px-4 py-3 border-t border-line/40 bg-panel/20">
                        <p class="text-xs font-semibold uppercase tracking-wider text-muted/70 mb-1.5">Insights</p>
                        <ul class="text-xs text-muted space-y-0.5 list-disc pl-4 m-0">
                            @foreach ($teamProgression['insights'] as $line)
                                <li>{{ $line }}</li>
                            @endforeach
                        </ul>
                    </aside>
                @endif

            </div>
        @elseif (empty($teamProgression['raids']))
            <div class="px-4 pb-4 border-t border-line pt-4">
                <p class="text-xs text-muted italic">
                    No Blizzard raid-encounters data yet. The daily blizzard:pull-raids sync populates the boss-by-boss breakdown.
                </p>
            </div>
        @endif
    @endif
</section>
