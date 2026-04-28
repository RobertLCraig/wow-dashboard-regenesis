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
                            @php $cls = 'cls-' . strtoupper($snap->member->class ?? ''); @endphp
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
                                    <x-weekly-key-cell :snap="$snap" />
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
