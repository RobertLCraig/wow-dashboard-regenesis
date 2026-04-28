@extends('layouts.dashboard')

@section('title', 'Keynight (M+)')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold">Keynight (M+)</h1>
        <span class="text-xs text-muted">
            Channel: <code class="text-ink">{{ $preset['channel_id'] ?? 'unset' }}</code>
            @if (! empty($preset['raid_days']))
                <span class="text-line">|</span>
                Night: {{ implode(', ', array_map(fn ($d) => \Carbon\CarbonImmutable::now()->startOfWeek()->addDays($d - 1)->format('D'), $preset['raid_days'])) }}
            @endif
        </span>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2">
            @php
                $scoreboardEmpty = 'No M+ data yet. Hit Sync now on <a href="' . route('admin.sync.index') . '" class="text-accent hover:underline">/admin/sync</a> to pull from Raider.IO.';
            @endphp
            <x-clarity-table
                :is-empty="$scoreboard['rows']->isEmpty()"
                searchable
                search-placeholder="Search character..."
                :meta="$scoreboard['captured_at'] ? 'raider.io ' . $scoreboard['captured_at']->diffForHumans() : 'no raider.io data'"
                :empty="$scoreboardEmpty"
            >
                <x-slot:header>
                    <h2 class="text-sm font-semibold uppercase tracking-wider">
                        M+ Scoreboard
                        <span class="text-muted text-xs font-normal normal-case ml-2">
                            top {{ $scoreboard['rows']->count() }} by current-season RIO
                        </span>
                    </h2>
                </x-slot:header>

                <table class="w-full text-sm clarity-tabular">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wider text-muted">
                            <th class="px-4 py-2 w-8 text-right cursor-pointer select-none hover:text-ink" @click="sortBy('rank')">
                                # <span class="text-muted" x-text="sortIcon('rank')"></span>
                            </th>
                            <th class="px-2 py-2 cursor-pointer select-none hover:text-ink" @click="sortBy('character')">
                                Character <span class="text-muted" x-text="sortIcon('character')"></span>
                            </th>
                            <th class="px-2 py-2 text-right cursor-pointer select-none hover:text-ink" @click="sortBy('rio')">
                                RIO <span class="text-muted" x-text="sortIcon('rio')"></span>
                            </th>
                            <th class="px-2 py-2 text-right cursor-pointer select-none hover:text-ink" @click="sortBy('key')">
                                Weekly key <span class="text-muted" x-text="sortIcon('key')"></span>
                            </th>
                            <th class="px-2 py-2 text-right cursor-pointer select-none hover:text-ink" @click="sortBy('ilvl')">
                                ilvl <span class="text-muted" x-text="sortIcon('ilvl')"></span>
                            </th>
                            <th class="px-4 py-2 text-right">Links</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($scoreboard['rows'] as $i => $snap)
                            @php
                                $cls = 'cls-' . strtoupper($snap->member->class ?? '');

                                /*
                                 * Per-run detail for the Weekly key cell. The single int we
                                 * already store on mplus_keystone is just `max(level)`; the full
                                 * list of weekly runs (with completed_at + timed status) lives
                                 * in raw_json under the raider.io profile key. Sort desc by
                                 * level so the headline run is index 0 and the popover reads
                                 * top-down.
                                 */
                                $weeklyRuns = collect($snap->raw_json['mythic_plus_weekly_highest_level_runs'] ?? [])
                                    ->filter(fn ($r) => is_array($r) && isset($r['mythic_level']))
                                    ->sortByDesc(fn ($r) => (int) ($r['mythic_level'] ?? 0))
                                    ->values();
                                $topRun = $weeklyRuns->first();
                                $topCompleted = null;
                                if (is_array($topRun) && is_string($topRun['completed_at'] ?? null)) {
                                    try {
                                        $topCompleted = \Carbon\CarbonImmutable::parse($topRun['completed_at']);
                                    } catch (\Throwable) {
                                    }
                                }
                                $level = (int) ($snap->mplus_keystone ?? 0);
                                $weeklyTone = $level >= 20 ? 'text-amber-300' : ($level >= 15 ? 'text-emerald-300' : 'text-ink');
                            @endphp
                            <tr class="border-t border-line" data-row>
                                <td class="px-4 py-2 font-mono text-muted text-right" data-sort-key="rank" data-sort-value="{{ $i + 1 }}">{{ $i + 1 }}</td>
                                <td class="px-2 py-2 truncate max-w-[260px]" data-sort-key="character" data-sort-value="{{ strtolower($snap->member->name) }}">
                                    <span class="inline-flex items-center gap-1.5">
                                        <x-class-icon :class="$snap->member->class" />
                                        <span class="{{ $cls }}">{{ $snap->member->name }}</span>
                                    </span>
                                </td>
                                <td class="px-2 py-2 font-mono text-right" data-label="RIO" data-sort-key="rio" data-sort-value="{{ $snap->mplus_score ?? 0 }}">
                                    {{ number_format($snap->mplus_score, 0) }}
                                </td>
                                <td class="relative px-2 py-2 text-right" data-label="Weekly key" data-sort-key="key" data-sort-value="{{ $snap->mplus_keystone ?? 0 }}">
                                    @if ($snap->mplus_keystone === null)
                                        <span class="font-mono text-muted">-</span>
                                    @else
                                        <div x-data="{ open: false }" @click.outside="open = false" class="inline-block text-right">
                                            <button type="button"
                                                    @click="open = !open"
                                                    class="font-mono inline-flex items-center gap-1.5 hover:text-accent transition-colors {{ $weeklyTone }}"
                                                    @if ($weeklyRuns->count() <= 1 && ! $topCompleted) disabled @endif>
                                                <span>+{{ $snap->mplus_keystone }}</span>
                                                @if ($topCompleted)
                                                    <span class="text-[10px] text-muted" title="{{ $topCompleted->toDayDateTimeString() }}">{{ $topCompleted->format('D') }}</span>
                                                @endif
                                                @if ($weeklyRuns->count() > 1)
                                                    <span class="text-[10px] text-muted" x-text="open ? '▾' : '▸'"></span>
                                                @endif
                                            </button>
                                            @if ($weeklyRuns->isNotEmpty())
                                                <div x-show="open" x-cloak x-transition.opacity
                                                     class="absolute right-2 top-full mt-1 z-20 min-w-[220px] bg-panel border border-line rounded-md shadow-lg p-2 text-left">
                                                    <div class="text-[10px] uppercase tracking-wider text-muted mb-1.5 px-1">This week's runs</div>
                                                    <ul class="space-y-1 text-xs font-mono">
                                                        @foreach ($weeklyRuns as $run)
                                                            @php
                                                                $runLevel = (int) ($run['mythic_level'] ?? 0);
                                                                $upg = (int) ($run['num_keystone_upgrades'] ?? 0);
                                                                $short = $run['short_name'] ?? ($run['dungeon'] ?? '?');
                                                                $when = null;
                                                                if (is_string($run['completed_at'] ?? null)) {
                                                                    try {
                                                                        $when = \Carbon\CarbonImmutable::parse($run['completed_at']);
                                                                    } catch (\Throwable) {
                                                                    }
                                                                }
                                                                $rowTone = match ($upg) {
                                                                    3 => 'text-amber-300',
                                                                    2, 1 => 'text-emerald-300',
                                                                    default => 'text-muted',
                                                                };
                                                                $upgMarks = $upg > 0 ? str_repeat('+', $upg) : '';
                                                            @endphp
                                                            <li class="flex items-center justify-between gap-3 px-1 py-0.5 rounded hover:bg-bg/60">
                                                                <span class="{{ $rowTone }}">+{{ $runLevel }}{{ $upgMarks ? ' ' . $upgMarks : '' }}</span>
                                                                <span class="text-ink truncate">{{ $short }}</span>
                                                                <span class="text-muted text-[10px]" @if ($when) title="{{ $when->toDayDateTimeString() }}" @endif>
                                                                    {{ $when?->format('D H:i') ?? '-' }}
                                                                </span>
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                    <div class="mt-1.5 pt-1.5 border-t border-line/60 text-[10px] text-muted px-1">
                                                        Untimed = grey, timed = green, +3 = amber
                                                    </div>
                                                </div>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                                <td class="px-2 py-2 font-mono text-right" data-label="ilvl" data-sort-key="ilvl" data-sort-value="{{ $snap->ilvl ?? 0 }}">{{ $snap->ilvl ?? '-' }}</td>
                                <td class="px-4 py-2 text-right" data-label="Links">
                                    <x-character-links :member="$snap->member" />
                                </td>
                            </tr>
                        @endforeach
                        <tr data-empty-message style="display:none">
                            <td colspan="6" class="px-4 py-4 text-center text-muted text-xs italic">No characters match.</td>
                        </tr>
                    </tbody>
                </table>
            </x-clarity-table>
        </div>

        <div class="space-y-6">
            @include('dashboard.widgets.quick-create', ['preset' => $preset, 'teamSlug' => 'keynight'])
            @include('dashboard.widgets.upcoming-events', ['upcomingEvents' => $upcomingEvents])
        </div>
    </div>
@endsection
