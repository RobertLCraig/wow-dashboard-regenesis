@extends('layouts.dashboard')

@section('title', $report->title)

@section('content')
    <div class="flex items-start justify-between gap-4 mb-4">
        <div>
            <h1 class="text-xl font-semibold">{{ $report->title }}</h1>
            <p class="text-sm text-muted mt-1">
                {{ $report->start_time?->format('D d M Y H:i') ?? '-' }}
                @if ($report->zone_name)
                    <span class="text-line">|</span>
                    {{ $report->zone_name }}
                @endif
                @if ($report->owner_name)
                    <span class="text-line">|</span>
                    Logged by {{ $report->owner_name }}
                @endif
            </p>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('reports.index') }}" class="text-sm text-muted hover:text-ink">&larr; All reports</a>
            <a href="{{ $report->jumpUrl() }}" target="_blank" rel="noopener noreferrer"
               class="text-sm px-3 py-1.5 rounded border border-line bg-bg hover:bg-panel">
                Open on WCL &rarr;
            </a>
        </div>
    </div>

    @if ($fights->isEmpty())
        <div class="bg-panel border border-line rounded-lg p-8 text-center text-muted text-sm">
            Fights haven't been imported for this report yet. The next WCL sync will backfill them.
        </div>
    @else
        @foreach ($fights as $fight)
            @php
                $tone = $fight->kill ? 'border-emerald-700/50 bg-emerald-950/10' : 'border-rose-700/40 bg-rose-950/10';
                $duration = $fight->duration_ms ? gmdate('i:s', (int) ($fight->duration_ms / 1000)) : null;
            @endphp
            <section class="rounded-lg border {{ $tone }} mb-4 overflow-hidden">
                <header class="px-5 py-3 border-b border-line flex items-center justify-between gap-3 flex-wrap">
                    <div>
                        <h2 class="font-semibold">
                            #{{ $fight->fight_id }} - {{ $fight->name }}
                            <span class="ml-2 text-xs uppercase tracking-wider text-muted">
                                {{ \App\Models\WclFight::difficultyLabel($fight->difficulty) }}
                            </span>
                        </h2>
                        <p class="text-xs text-muted mt-0.5">
                            @if ($fight->kill)
                                <span class="text-emerald-300">Kill</span>
                            @else
                                <span class="text-rose-300">Wipe</span>
                                @if ($fight->best_percentage !== null)
                                    @ {{ rtrim(rtrim(number_format($fight->best_percentage, 2), '0'), '.') }}%
                                @endif
                            @endif
                            @if ($duration)
                                <span class="text-line">|</span> {{ $duration }}
                            @endif
                        </p>
                    </div>
                </header>

                @if ($fight->parses->isEmpty())
                    <div class="px-5 py-3 text-xs text-muted italic">No per-actor data captured for this pull.</div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs uppercase tracking-wider text-muted">
                                    <th class="px-5 py-2">Player</th>
                                    <th class="px-2 py-2">Class</th>
                                    <th class="px-2 py-2">Role</th>
                                    <th class="px-2 py-2 text-right">Per second</th>
                                    <th class="px-2 py-2 text-right">ilvl</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($fight->parses as $p)
                                    @php $cls = 'cls-' . strtoupper($p->actor_class ?? ''); @endphp
                                    <tr class="border-t border-line">
                                        <td class="px-5 py-1.5">
                                            <span class="{{ $cls }}">{{ $p->actor_name }}</span>
                                            @if ($p->member_id)
                                                <span class="text-[10px] uppercase tracking-wider text-muted ml-2">guild</span>
                                            @endif
                                        </td>
                                        <td class="px-2 py-1.5 text-muted text-xs">{{ $p->actor_class ?? '-' }}</td>
                                        <td class="px-2 py-1.5 text-muted text-xs">{{ ucfirst($p->role ?? '-') }}</td>
                                        <td class="px-2 py-1.5 font-mono text-right">
                                            {{ $p->metric_per_second !== null ? number_format($p->metric_per_second, 0) : '-' }}
                                        </td>
                                        <td class="px-2 py-1.5 font-mono text-right">{{ $p->item_level ?? '-' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endforeach
    @endif
@endsection
