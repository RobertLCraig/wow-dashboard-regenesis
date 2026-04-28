@php
    /**
     * @var array{
     *   summary: array<string,array{count:int,highest:?int,timed:int}>,
     *   heatmap: array{from:\Carbon\CarbonImmutable, to:\Carbon\CarbonImmutable, days:array<string,array{count:int,highest:int,timed:int}>},
     *   by_dungeon: list<array{dungeon:string,short:?string,count:int,highest:int,timed:int}>,
     *   recent: \Illuminate\Support\Collection<int,\App\Models\MemberMplusRun>,
     * } $mplusActivity
     */
    $hasAny = $mplusActivity['summary']['90d']['count'] > 0;
@endphp

<section class="bg-panel border border-line rounded-lg overflow-hidden mb-6">
    <header class="px-4 py-3 border-b border-line flex items-center justify-between">
        <h2 class="text-sm font-semibold uppercase tracking-wider">Mythic+ activity</h2>
        <span class="text-xs text-muted">trailing 90 days</span>
    </header>

    @if (! $hasAny)
        <div class="p-6 text-center text-muted text-sm">
            No keys completed in the last 90 days.
        </div>
    @else
        {{-- Summary tiles: 7d / 30d / 90d at a glance --}}
        <div class="grid grid-cols-3 gap-px bg-line">
            @foreach (['7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days'] as $key => $label)
                @php $s = $mplusActivity['summary'][$key]; @endphp
                <div class="bg-panel px-4 py-3">
                    <div class="text-[10px] uppercase tracking-wider text-muted">{{ $label }}</div>
                    <div class="font-mono text-2xl mt-1">{{ $s['count'] }}</div>
                    <div class="text-xs text-muted mt-0.5">
                        @if ($s['count'] > 0)
                            highest +{{ $s['highest'] }}, {{ $s['timed'] }} timed
                        @else
                            no keys
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Heatmap: rows are weekdays (Mon..Sun), columns are weeks
             oldest-left → newest-right. Count drives opacity, highest
             level drives the fill colour band. Hover reveals the full
             day breakdown. --}}
        @php
            $days = $mplusActivity['heatmap']['days'];
            $weeks = [];
            foreach ($days as $iso => $cell) {
                $d = \Carbon\CarbonImmutable::parse($iso);
                // Monday-anchored ISO week-year key so a Sun-Mon
                // boundary doesn't split a column.
                $weekKey = $d->isoFormat('GGGG-WW');
                // 0=Mon, 6=Sun for grid placement
                $weekday = ((int) $d->dayOfWeekIso) - 1;
                $weeks[$weekKey][$weekday] = ['iso' => $iso, 'cell' => $cell];
            }
            // Order columns chronologically by their first cell.
            uksort($weeks, fn ($a, $b) => $a <=> $b);

            // Map highest-level to a tone class. Using static Tailwind
            // class names so the JIT picks them up at build time.
            $tone = function (int $highest, int $count): string {
                if ($count === 0) return 'bg-bg/40 border-line/40';
                if ($highest >= 18) return 'bg-rose-500/70 border-rose-400/70';
                if ($highest >= 15) return 'bg-amber-400/70 border-amber-300/70';
                if ($highest >= 12) return 'bg-emerald-400/70 border-emerald-300/70';
                if ($highest >= 8)  return 'bg-emerald-600/60 border-emerald-500/60';
                return 'bg-emerald-800/60 border-emerald-700/60';
            };
            $weekdayLabels = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
        @endphp

        <div class="px-4 py-4">
            <div class="text-[10px] uppercase tracking-wider text-muted mb-2">
                Daily activity (each column = one week, oldest left)
            </div>
            <div class="flex gap-2">
                <div class="flex flex-col gap-1 text-[10px] text-muted/70 pt-px">
                    @foreach ($weekdayLabels as $i => $w)
                        {{-- Show every other label to save vertical space. --}}
                        <div class="h-3.5 flex items-center">{{ $i % 2 === 0 ? $w : '' }}</div>
                    @endforeach
                </div>
                <div class="flex gap-1 overflow-x-auto pb-1">
                    @foreach ($weeks as $weekKey => $cellsByWeekday)
                        <div class="flex flex-col gap-1">
                            @for ($wd = 0; $wd < 7; $wd++)
                                @php
                                    $entry = $cellsByWeekday[$wd] ?? null;
                                    $cell = $entry['cell'] ?? ['count' => 0, 'highest' => 0, 'timed' => 0];
                                    $iso = $entry['iso'] ?? null;
                                    $title = $iso
                                        ? ($cell['count'] === 0
                                            ? \Carbon\CarbonImmutable::parse($iso)->format('D d M Y') . ': no keys'
                                            : \Carbon\CarbonImmutable::parse($iso)->format('D d M Y') . ': ' . $cell['count'] . ' run' . ($cell['count'] === 1 ? '' : 's') . ', highest +' . $cell['highest'] . ($cell['timed'] < $cell['count'] ? ' (' . ($cell['count'] - $cell['timed']) . ' depleted)' : ''))
                                        : '';
                                @endphp
                                <div class="w-3.5 h-3.5 rounded-[2px] border {{ $tone($cell['highest'], $cell['count']) }}"
                                     title="{{ $title }}"></div>
                            @endfor
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Legend --}}
            <div class="flex items-center gap-3 mt-3 text-[10px] text-muted">
                <span>Highest level:</span>
                <span class="inline-flex items-center gap-1"><span class="w-3 h-3 rounded-[2px] bg-emerald-800/60 border border-emerald-700/60"></span>+2-7</span>
                <span class="inline-flex items-center gap-1"><span class="w-3 h-3 rounded-[2px] bg-emerald-600/60 border border-emerald-500/60"></span>+8-11</span>
                <span class="inline-flex items-center gap-1"><span class="w-3 h-3 rounded-[2px] bg-emerald-400/70 border border-emerald-300/70"></span>+12-14</span>
                <span class="inline-flex items-center gap-1"><span class="w-3 h-3 rounded-[2px] bg-amber-400/70 border border-amber-300/70"></span>+15-17</span>
                <span class="inline-flex items-center gap-1"><span class="w-3 h-3 rounded-[2px] bg-rose-500/70 border border-rose-400/70"></span>+18+</span>
            </div>
        </div>

        {{-- Two columns: dungeon distribution (30d) + recent run table --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-px bg-line">
            <div class="bg-panel px-4 py-3">
                <h3 class="text-[10px] uppercase tracking-wider text-muted mb-2">Dungeon spread (30d)</h3>
                @if (empty($mplusActivity['by_dungeon']))
                    <p class="text-xs text-muted italic">No keys in the last 30 days.</p>
                @else
                    @php $maxCount = max(array_column($mplusActivity['by_dungeon'], 'count')); @endphp
                    <ul class="space-y-1.5">
                        @foreach ($mplusActivity['by_dungeon'] as $d)
                            @php $pct = $maxCount > 0 ? ($d['count'] / $maxCount) * 100 : 0; @endphp
                            <li class="grid grid-cols-[6rem_1fr_3rem] items-center gap-2 text-xs">
                                <span class="truncate" title="{{ $d['dungeon'] }}">
                                    {{ $d['short'] ?? \Illuminate\Support\Str::limit($d['dungeon'], 8) }}
                                </span>
                                <span class="h-2 rounded bg-emerald-700/40 relative overflow-hidden">
                                    <span class="absolute inset-y-0 left-0 bg-emerald-400/70 rounded"
                                          style="width: {{ $pct }}%"></span>
                                </span>
                                <span class="text-right font-mono text-muted">
                                    {{ $d['count'] }}<span class="text-line">/</span><span class="text-ink">+{{ $d['highest'] }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="bg-panel px-4 py-3">
                <h3 class="text-[10px] uppercase tracking-wider text-muted mb-2">Recent runs ({{ $mplusActivity['recent']->count() }})</h3>
                @if ($mplusActivity['recent']->isEmpty())
                    <p class="text-xs text-muted italic">No recent runs.</p>
                @else
                    <ul class="space-y-1 text-xs max-h-72 overflow-y-auto pr-1">
                        @foreach ($mplusActivity['recent'] as $r)
                            @php
                                $upgrades = $r->num_keystone_upgrades;
                                $resultLabel = match ($upgrades) {
                                    3 => '+3',
                                    2 => '+2',
                                    1 => '+1',
                                    default => 'depleted',
                                };
                                $resultTone = $upgrades > 0 ? 'text-emerald-300' : 'text-muted';
                            @endphp
                            <li class="grid grid-cols-[5rem_1fr_3rem_3.5rem] items-center gap-2">
                                <span class="text-muted whitespace-nowrap">
                                    {{ $r->completed_at->format('D d M') }}
                                </span>
                                <span class="truncate" title="{{ $r->dungeon_name }}">
                                    {{ $r->dungeon_short_name ?? $r->dungeon_name ?? '?' }}
                                </span>
                                <span class="font-mono text-right">+{{ $r->mythic_level }}</span>
                                <span class="text-right {{ $resultTone }}">{{ $resultLabel }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    @endif
</section>
