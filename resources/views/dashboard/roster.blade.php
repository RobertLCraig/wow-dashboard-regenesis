@extends('layouts.dashboard')

@section('title', 'Roster')

@section('content')
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-xl font-semibold">Roster</h1>
        <a href="{{ route('roster.csv', ['filter' => $filter]) }}"
           class="text-sm px-3 py-1.5 rounded border border-line bg-bg hover:bg-panel">
            Export CSV
        </a>
    </div>

    @php
        /**
         * Filter chips. Each is a link that swaps `?filter=`. Active chip
         * gets the accent border; counts come from the controller so the
         * officer sees what each chip will reveal before clicking.
         */
        $chips = [
            ['key' => 'all',           'label' => 'All'],
            ['key' => 'inactive_7d',   'label' => 'Inactive 7d'],
            ['key' => 'inactive_14d',  'label' => 'Inactive 14d'],
            ['key' => 'inactive_30d',  'label' => 'Inactive 30d'],
            ['key' => 'inactive_60d',  'label' => 'Inactive 60d'],
            ['key' => 'inactive_90d',  'label' => 'Inactive 90d'],
            ['key' => 'mains',         'label' => 'Mains'],
            ['key' => 'alts',          'label' => 'Alts'],
            ['key' => 'trial',         'label' => 'Trial'],
            ['key' => 'action_queue',  'label' => 'Action queue'],
            ['key' => 'banned',        'label' => 'Banned'],
        ];
    @endphp

    <div class="flex flex-wrap gap-2 mb-4">
        @foreach ($chips as $chip)
            @php
                $active = $filter === $chip['key'];
                $count = $counts[$chip['key']] ?? 0;
            @endphp
            <a href="{{ route('roster.index', ['filter' => $chip['key']]) }}"
               class="text-xs px-2 py-1 rounded border transition
                      {{ $active
                          ? 'border-accent bg-accent/15 text-ink'
                          : 'border-line bg-bg text-muted hover:text-ink hover:border-muted' }}">
                {{ $chip['label'] }}
                <span class="ml-1 text-[10px] {{ $active ? 'text-ink/80' : 'text-muted/70' }}">{{ $count }}</span>
            </a>
        @endforeach
    </div>

    <x-clarity-table
        :is-empty="$rows->isEmpty()"
        searchable
        search-placeholder="Search name, class, rank, team..."
        empty="No members match this filter."
    >
        <x-slot:header>
            <h2 class="text-sm font-semibold uppercase tracking-wider">
                {{ $rows->count() }} {{ \Illuminate\Support\Str::plural('member', $rows->count()) }}
            </h2>
        </x-slot:header>

        <table class="w-full text-sm clarity-tabular">
            <thead>
                <tr class="text-left text-xs uppercase tracking-wider text-muted">
                    <th class="px-4 py-2 font-medium cursor-pointer select-none hover:text-ink" @click="sortBy('name')">
                        Name <span class="text-muted" x-text="sortIcon('name')"></span>
                    </th>
                    <th class="px-2 py-2 font-medium cursor-pointer select-none hover:text-ink" @click="sortBy('class')">
                        Class <span class="text-muted" x-text="sortIcon('class')"></span>
                    </th>
                    <th class="px-2 py-2 font-medium cursor-pointer select-none hover:text-ink" @click="sortBy('rank')">
                        Rank <span class="text-muted" x-text="sortIcon('rank')"></span>
                    </th>
                    <th class="px-2 py-2 font-medium cursor-pointer select-none hover:text-ink text-right" @click="sortBy('ilvl')">
                        ilvl <span class="text-muted" x-text="sortIcon('ilvl')"></span>
                    </th>
                    <th class="px-2 py-2 font-medium cursor-pointer select-none hover:text-ink text-right" @click="sortBy('rio')">
                        RIO <span class="text-muted" x-text="sortIcon('rio')"></span>
                    </th>
                    <th class="px-2 py-2 font-medium cursor-pointer select-none hover:text-ink" @click="sortBy('lastseen')">
                        Last seen <span class="text-muted" x-text="sortIcon('lastseen')"></span>
                    </th>
                    <th class="px-2 py-2 font-medium">Alt of</th>
                    <th class="px-2 py-2 font-medium">Flags</th>
                    <th class="px-2 py-2 font-medium text-right">Links</th>
                    @can('roster.kick')
                        <th class="px-4 py-2 font-medium text-right">Actions</th>
                    @endcan
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    @php
                        $m = $row['member'];
                        $snap = $row['snap'];
                        $cls = 'cls-' . strtoupper($m->class ?? '');
                    @endphp
                    <tr class="border-t border-line" data-row>
                        <td class="px-4 py-2" data-sort-key="name" data-sort-value="{{ strtolower($m->name) }}">
                            <a href="{{ route('character.show', $m->name) }}" class="{{ $cls }} hover:underline">{{ $m->name }}</a>
                            @if ($m->level)
                                <span class="text-muted text-xs ml-1">L{{ $m->level }}</span>
                            @endif
                        </td>
                        <td class="px-2 py-2 text-muted" data-label="Class" data-sort-key="class" data-sort-value="{{ strtolower($m->class ?? '') }}">
                            {{ $m->class }}
                        </td>
                        <td class="px-2 py-2 text-muted" data-label="Rank" data-sort-key="rank" data-sort-value="{{ $m->rank_index ?? 99 }}">
                            {{ $m->rank_name }}
                        </td>
                        <td class="px-2 py-2 font-mono text-right" data-label="ilvl" data-sort-key="ilvl" data-sort-value="{{ $snap?->ilvl ?? 0 }}">
                            {{ $snap?->ilvl ?? '-' }}
                        </td>
                        <td class="px-2 py-2 font-mono text-right" data-label="RIO" data-sort-key="rio" data-sort-value="{{ $snap?->mplus_score ?? 0 }}">
                            {{ $snap?->mplus_score !== null ? number_format($snap->mplus_score, 0) : '-' }}
                        </td>
                        <td class="px-2 py-2 text-muted whitespace-nowrap"
                            data-label="Last seen"
                            data-sort-key="lastseen"
                            data-sort-value="{{ $m->last_online_at?->timestamp ?? 0 }}">
                            {{ $m->last_online_at?->diffForHumans() ?? 'never' }}
                        </td>
                        <td class="px-2 py-2 text-muted text-xs" data-label="Alt of">
                            @if ($row['main'])
                                {{ $row['main']->name }}
                            @endif
                        </td>
                        <td class="px-2 py-2" data-label="Flags">
                            @foreach ($row['flags'] as $flag)
                                @php
                                    $tone = match ($flag) {
                                        'promote' => 'border-emerald-700/50 text-emerald-300',
                                        'demote'  => 'border-amber-700/50 text-amber-300',
                                        'kick','banned' => 'border-rose-700/50 text-rose-300',
                                        default => 'border-line text-muted',
                                    };
                                @endphp
                                <span class="inline-block text-[10px] uppercase tracking-wider border rounded px-1 py-0.5 mr-1 {{ $tone }}">
                                    {{ $flag }}
                                </span>
                            @endforeach
                        </td>
                        <td class="px-2 py-2 text-right" data-label="Links">
                            <x-character-links :member="$m" />
                        </td>
                        @can('roster.kick')
                            <td class="px-4 py-2 text-right" data-label="Actions">
                                <button type="button"
                                        @click="$dispatch('open-kick-macro', { ids: {{ json_encode($row['group_member_ids']) }} })"
                                        class="text-[10px] uppercase tracking-wider px-2 py-0.5 rounded border border-rose-700/50 text-rose-300 hover:bg-rose-950/30"
                                        title="Generate /gremove macro for {{ $m->name }}{{ count($row['group_member_ids']) > 1 ? ' + ' . (count($row['group_member_ids']) - 1) . ' alts' : '' }}">
                                    Kick{{ count($row['group_member_ids']) > 1 ? ' +' . (count($row['group_member_ids']) - 1) : '' }}
                                </button>
                            </td>
                        @endcan
                    </tr>
                @endforeach
                <tr data-empty-message style="display:none">
                    <td colspan="{{ auth()->user()?->can('roster.kick') ? 10 : 9 }}" class="px-4 py-4 text-center text-muted text-xs italic">No matches.</td>
                </tr>
            </tbody>
        </table>
    </x-clarity-table>

    @can('roster.kick')
        <x-kick-macro-modal />
    @endcan
@endsection
