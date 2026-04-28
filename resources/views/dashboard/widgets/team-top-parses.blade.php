@php
    /**
     * Best parse_percentile per team member in the last 14 days.
     * Members with no parse in the window are dropped upstream.
     */
@endphp
<section class="bg-panel border border-line rounded-lg overflow-hidden">
    <header class="px-4 py-3 border-b border-line flex items-center justify-between">
        <h2 class="text-sm font-semibold uppercase tracking-wider">
            Best parses (last 14 days)
        </h2>
        <span class="text-xs text-muted">
            {{ $topParses->count() }} {{ \Illuminate\Support\Str::plural('member', $topParses->count()) }} ranked
        </span>
    </header>
    @if ($topParses->isEmpty())
        <div class="p-6 text-center text-muted text-sm">
            No ranked parses yet for this team's roster. Run a WCL sync from
            <a href="{{ route('admin.sync.index') }}" class="text-accent hover:underline">/admin/sync</a>.
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm" x-data="{ openCol: null }">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wider text-muted">
                        <th class="px-4 py-2 w-8 text-right">
                            #
                            <x-column-explainer-toggle col="rank" />
                        </th>
                        <th class="px-2 py-2">
                            Player
                            <x-column-explainer-toggle col="player" />
                        </th>
                        <th class="px-2 py-2 text-right">
                            Parse
                            <x-column-explainer-toggle col="parse" />
                        </th>
                        <th class="px-2 py-2">
                            Boss
                            <x-column-explainer-toggle col="boss" />
                        </th>
                        <th class="px-2 py-2">
                            Diff
                            <x-column-explainer-toggle col="diff" />
                        </th>
                        <th class="px-2 py-2 text-right">
                            Per second
                            <x-column-explainer-toggle col="per_second" />
                        </th>
                        <th class="px-4 py-2 text-right">
                            When
                            <x-column-explainer-toggle col="when" />
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr x-show="openCol !== null" x-cloak class="border-t border-line bg-bg/40">
                        <td colspan="7" class="px-4 py-3 text-xs text-muted leading-relaxed normal-case tracking-normal font-normal">
                            <template x-if="openCol === 'rank'">
                                <div>
                                    <span class="block text-ink font-semibold mb-1">#</span>
                                    Rank within the last 14 days; the member with the highest parse
                                    percentile is first. Members with no ranked parse in the window
                                    are dropped, so an empty list usually means no recent WCL sync
                                    rather than a team of bad players.
                                </div>
                            </template>
                            <template x-if="openCol === 'player'">
                                <div>
                                    <span class="block text-ink font-semibold mb-1">Player</span>
                                    Character coloured by class. Click to open the dashboard
                                    character page.
                                </div>
                            </template>
                            <template x-if="openCol === 'parse'">
                                <div>
                                    <span class="block text-ink font-semibold mb-1">Parse</span>
                                    Best WCL parse percentile this character has logged in the last
                                    14 days. 95+ is gold, 75+ is purple, 50 is the median.
                                </div>
                            </template>
                            <template x-if="openCol === 'boss'">
                                <div>
                                    <span class="block text-ink font-semibold mb-1">Boss</span>
                                    Boss the parse came from. Click to open the dashboard view of
                                    the WCL report.
                                </div>
                            </template>
                            <template x-if="openCol === 'diff'">
                                <div>
                                    <span class="block text-ink font-semibold mb-1">Diff</span>
                                    WCL difficulty (Normal, Heroic, Mythic, etc.).
                                </div>
                            </template>
                            <template x-if="openCol === 'per_second'">
                                <div>
                                    <span class="block text-ink font-semibold mb-1">Per second</span>
                                    DPS for damage dealers and HPS for healers, averaged over the
                                    full pull duration.
                                </div>
                            </template>
                            <template x-if="openCol === 'when'">
                                <div>
                                    <span class="block text-ink font-semibold mb-1">When</span>
                                    When the pull happened, relative to now. To pick up newer logs,
                                    run a WCL sync from /admin/sync.
                                </div>
                            </template>
                        </td>
                    </tr>
                    @foreach ($topParses as $i => $row)
                        @php
                            $m = $row['member'];
                            $p = $row['parse'];
                            $f = $p->fight;
                            $cls = 'cls-' . strtoupper($m->class ?? '');
                        @endphp
                        <tr class="border-t border-line">
                            <td class="px-4 py-1.5 font-mono text-muted text-right">{{ $i + 1 }}</td>
                            <td class="px-2 py-1.5">
                                <a href="{{ route('character.show', $m->name) }}" class="{{ $cls }} hover:underline">{{ $m->name }}</a>
                            </td>
                            <td class="px-2 py-1.5 text-right">
                                <x-parse-pill :percentile="$p->parse_percentile" />
                            </td>
                            <td class="px-2 py-1.5 text-xs">
                                @if ($f?->report)
                                    <a href="{{ route('reports.show', $f->report->code) }}" class="hover:underline">{{ $f?->name ?? '-' }}</a>
                                @else
                                    {{ $f?->name ?? '-' }}
                                @endif
                            </td>
                            <td class="px-2 py-1.5 text-xs text-muted">
                                {{ \App\Models\WclFight::difficultyLabel($f?->difficulty) }}
                            </td>
                            <td class="px-2 py-1.5 font-mono text-right">
                                {{ $p->metric_per_second !== null ? number_format($p->metric_per_second, 0) : '-' }}
                            </td>
                            <td class="px-4 py-1.5 text-xs text-muted text-right whitespace-nowrap">
                                {{ $f?->start_time?->diffForHumans() ?? '-' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
