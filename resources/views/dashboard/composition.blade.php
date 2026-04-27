@extends('layouts.dashboard')

@section('title', $teamLabel . ' Composition')

@section('content')
    <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
        <h1 class="text-xl font-semibold">
            {{ $teamLabel }} Composition
            <span class="text-muted text-sm font-normal ml-2">{{ $memberCount }} on roster</span>
        </h1>
        <a href="{{ route('dashboard.team.' . $teamSlug) }}"
           class="text-xs text-muted hover:text-ink underline-offset-2 hover:underline">
            ← back to {{ $teamLabel }} Team
        </a>
    </div>

    {{-- Filter bar. Plain form GETs so the URL is shareable. --}}
    <form method="GET" action="{{ route('composition.show', $teamSlug) }}"
          class="flex flex-wrap items-center gap-3 mb-6 text-sm">
        <label class="flex items-center gap-2">
            <span class="text-muted text-xs uppercase tracking-wider">Window</span>
            <select name="days" onchange="this.form.submit()"
                    class="bg-bg border border-line rounded px-2 py-1 text-xs">
                @foreach ([7, 14, 30, 60] as $n)
                    <option value="{{ $n }}" {{ $days === $n ? 'selected' : '' }}>{{ $n }} days</option>
                @endforeach
            </select>
        </label>
        <label class="flex items-center gap-2">
            <span class="text-muted text-xs uppercase tracking-wider">Difficulty</span>
            <select name="difficulty" onchange="this.form.submit()"
                    class="bg-bg border border-line rounded px-2 py-1 text-xs">
                @php $diffH = \App\Models\WclFight::DIFFICULTY_HEROIC; $diffM = \App\Models\WclFight::DIFFICULTY_MYTHIC; @endphp
                <option value="{{ $diffH }}" {{ $difficulty === $diffH ? 'selected' : '' }}>Heroic</option>
                <option value="{{ $diffM }}" {{ $difficulty === $diffM ? 'selected' : '' }}>Mythic</option>
                <option value="all" {{ $difficulty === 'all' ? 'selected' : '' }}>Any</option>
            </select>
        </label>
        <noscript>
            <button type="submit" class="text-xs px-3 py-1 rounded border border-line bg-bg hover:bg-panel">Apply</button>
        </noscript>
    </form>

    @php
        // "No parses" empty state when nothing landed in a role bucket;
        // having only the 'unknown' bucket counts as empty since it
        // means we have members but no WCL signal to classify them.
        $hasClassifiedBucket = collect(\App\Services\Composition\SpecRoleMap::orderedRoles())
            ->some(fn ($r) => ! empty($buckets[$r] ?? []));
    @endphp
    @if (! $hasClassifiedBucket)
        <div class="bg-panel border border-line rounded-lg p-8 text-center text-muted text-sm">
            No parses recorded for this team in the last {{ $days }} days.
            Try a wider window, or run a WCL sync from
            <a href="{{ route('admin.sync.index') }}" class="text-accent hover:underline">/admin/sync</a>.
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            @foreach (\App\Services\Composition\SpecRoleMap::orderedRoles() as $role)
                @if (! empty($buckets[$role] ?? []))
                    <x-clarity-table
                        :title="\App\Services\Composition\SpecRoleMap::label($role) . ' (' . count($buckets[$role]) . ')'"
                        :is-empty="false"
                        searchable
                        search-placeholder="Filter..."
                    >
                        <table class="w-full text-sm clarity-tabular">
                            <thead>
                                <tr class="text-left text-xs uppercase tracking-wider text-muted">
                                    <th class="px-4 py-2 font-medium cursor-pointer select-none hover:text-ink" @click="sortBy('name')">
                                        Player <span class="text-muted" x-text="sortIcon('name')"></span>
                                    </th>
                                    <th class="px-2 py-2 font-medium text-right cursor-pointer select-none hover:text-ink" @click="sortBy('avg')">
                                        Avg <span class="text-muted" x-text="sortIcon('avg')"></span>
                                    </th>
                                    <th class="px-2 py-2 font-medium text-right cursor-pointer select-none hover:text-ink" @click="sortBy('best')">
                                        Best <span class="text-muted" x-text="sortIcon('best')"></span>
                                    </th>
                                    <th class="px-2 py-2 font-medium text-right cursor-pointer select-none hover:text-ink" @click="sortBy('count')">
                                        # <span class="text-muted" x-text="sortIcon('count')"></span>
                                    </th>
                                    <th class="px-2 py-2 font-medium text-xs">Spec</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($buckets[$role] as $row)
                                    @php
                                        $m   = $row['member'];
                                        $cls = 'cls-' . strtoupper($m->class ?? '');
                                    @endphp
                                    <tr class="border-t border-line" data-row>
                                        <td class="px-4 py-2"
                                            data-sort-key="name"
                                            data-sort-value="{{ strtolower($m->name) }}">
                                            <span class="inline-flex items-center gap-1.5">
                                                <x-class-icon :class="$row['latest_class'] ?? $m->class" />
                                                <a href="{{ route('character.show', $m->name) }}"
                                                   class="{{ $cls }} hover:underline">{{ $m->name }}</a>
                                            </span>
                                        </td>
                                        <td class="px-2 py-2 text-right"
                                            data-label="Avg parse"
                                            data-sort-key="avg"
                                            data-sort-value="{{ $row['avg_parse'] ?? -1 }}">
                                            @if ($row['avg_parse'] !== null)
                                                <x-parse-pill :percentile="(int) round($row['avg_parse'])" />
                                            @else
                                                <span class="text-muted text-xs">-</span>
                                            @endif
                                        </td>
                                        <td class="px-2 py-2 text-right"
                                            data-label="Best parse"
                                            data-sort-key="best"
                                            data-sort-value="{{ $row['best_parse'] ?? -1 }}">
                                            @if ($row['best_parse'] !== null)
                                                <x-parse-pill :percentile="$row['best_parse']" />
                                            @else
                                                <span class="text-muted text-xs">-</span>
                                            @endif
                                        </td>
                                        <td class="px-2 py-2 text-right text-xs text-muted font-mono"
                                            data-label="Fights"
                                            data-sort-key="count"
                                            data-sort-value="{{ $row['parses_count'] }}">
                                            {{ $row['parses_count'] }}
                                        </td>
                                        <td class="px-2 py-2 text-xs text-muted truncate max-w-[110px]"
                                            data-label="Spec">
                                            {{ $row['latest_spec'] ?? '-' }}
                                        </td>
                                    </tr>
                                @endforeach
                                <tr data-empty-message style="display:none">
                                    <td colspan="5" class="px-4 py-4 text-center text-muted text-xs italic">No matches.</td>
                                </tr>
                            </tbody>
                        </table>
                    </x-clarity-table>
                @endif
            @endforeach

            @if (! empty($buckets['unknown'] ?? []))
                <div class="bg-panel border border-line rounded-lg p-4 text-xs text-muted col-span-full">
                    <div class="font-semibold uppercase tracking-wider mb-2">Unclassified</div>
                    {{ count($buckets['unknown']) }} member(s) haven't appeared in a WCL report this window so we can't infer their role yet:
                    {{ collect($buckets['unknown'])->pluck('member.name')->implode(', ') }}.
                </div>
            @endif
        </div>
    @endif
@endsection
