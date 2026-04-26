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
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wider text-muted">
                        <th class="px-4 py-2 w-8 text-right">#</th>
                        <th class="px-2 py-2">Player</th>
                        <th class="px-2 py-2 text-right">Parse</th>
                        <th class="px-2 py-2">Boss</th>
                        <th class="px-2 py-2">Diff</th>
                        <th class="px-2 py-2 text-right">Per second</th>
                        <th class="px-4 py-2 text-right">When</th>
                    </tr>
                </thead>
                <tbody>
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
