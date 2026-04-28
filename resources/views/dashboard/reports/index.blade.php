@extends('layouts.dashboard')

@section('title', 'Logs')

@section('content')
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-xl font-semibold">Warcraft Logs</h1>
        <a href="{{ route('admin.sync.index') }}" class="text-sm text-accent hover:underline">Sync now</a>
    </div>

    <section class="bg-panel border border-line rounded-lg overflow-hidden" x-data="sortableTable()">
        <header class="px-4 py-3 border-b border-line flex items-center justify-between gap-3">
            <h2 class="text-sm font-semibold uppercase tracking-wider">
                {{ $reports->count() }} {{ \Illuminate\Support\Str::plural('report', $reports->count()) }}
            </h2>
            <input type="text" x-model="search" placeholder="Search title, zone, owner..."
                   class="bg-bg border border-line rounded px-2 py-1 text-xs w-56 placeholder:text-muted">
        </header>
        @if ($reports->isEmpty())
            <div class="p-8 text-center text-muted text-sm">
                No reports stored yet. Run a sync from
                <a href="{{ route('admin.sync.index') }}" class="text-accent hover:underline">/admin/sync</a>.
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wider text-muted">
                            <th class="px-4 py-2 cursor-pointer hover:text-ink select-none" @click="sortBy('start')">
                                When <span class="text-muted" x-text="sortIcon('start')"></span>
                            </th>
                            <th class="px-2 py-2 cursor-pointer hover:text-ink select-none" @click="sortBy('title')">
                                Title <span class="text-muted" x-text="sortIcon('title')"></span>
                            </th>
                            <th class="px-2 py-2 cursor-pointer hover:text-ink select-none" @click="sortBy('zone')">
                                Zone <span class="text-muted" x-text="sortIcon('zone')"></span>
                            </th>
                            <th class="px-2 py-2 text-right">Pulls / Kills</th>
                            <th class="px-2 py-2 text-muted text-xs">Owner</th>
                            <th class="px-4 py-2 text-right">Links</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($reports as $r)
                            <tr class="border-t border-line" data-row>
                                <td class="px-4 py-2 whitespace-nowrap text-muted text-xs"
                                    data-sort-key="start" data-sort-value="{{ $r->start_time?->timestamp ?? 0 }}">
                                    {{ $r->start_time?->format('D d M Y H:i') ?? '-' }}
                                </td>
                                <td class="px-2 py-2"
                                    data-sort-key="title" data-sort-value="{{ strtolower($r->title) }}">
                                    <a href="{{ route('reports.show', $r->code) }}" class="hover:text-accent">{{ $r->title }}</a>
                                </td>
                                <td class="px-2 py-2 text-muted text-xs"
                                    data-sort-key="zone" data-sort-value="{{ strtolower($r->zone_name ?? '') }}">
                                    {{ $r->zone_name ?? '-' }}
                                </td>
                                <td class="px-2 py-2 font-mono text-right">
                                    @if ($r->fights_count > 0)
                                        {{ $r->fights_count }}
                                        <span class="text-muted">/</span>
                                        <span class="text-emerald-300">{{ $r->kills_count }}</span>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td class="px-2 py-2 text-muted text-xs">{{ $r->owner_name ?? '-' }}</td>
                                <td class="px-4 py-2 text-right">
                                    <a href="{{ $r->jumpUrl() }}" target="_blank" rel="noopener noreferrer"
                                       class="text-[10px] font-mono uppercase text-muted hover:text-accent border border-line hover:border-accent rounded px-1 py-0.5">
                                        WCL
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                        <tr data-empty-message style="display:none">
                            <td colspan="6" class="px-4 py-4 text-center text-muted text-xs italic">No matches.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
