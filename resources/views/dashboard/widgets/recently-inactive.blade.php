<x-clarity-table
    title="Recently inactive"
    :is-empty="$inactive->isEmpty()"
    searchable
    search-placeholder="Search name or rank..."
    :count="$inactive->count() . ' shown'"
    empty="No members inactive over 30 days."
>
    <x-slot:header>
        <h2 class="text-sm font-semibold uppercase tracking-wider flex items-center gap-2">
            <span>Recently inactive</span>
            <x-explainer-toggle />
        </h2>
    </x-slot:header>

    <x-slot:explainer>
        <x-explainer-panel title="Recently inactive">
            Active members who have crossed the 30-day no-login threshold but are not
            yet in the action queue. Sort by last seen to find the longest-gone, or by
            rank to spot officers and raiders that should be demoted or moved into an
            alt group. Anything past 90 days is highlighted in red. Last-online comes
            from GRM's per-character timestamp.
        </x-explainer-panel>
    </x-slot:explainer>

    <table class="w-full text-sm clarity-tabular">
        <thead>
            <tr class="text-left text-xs uppercase tracking-wider text-muted">
                <th class="px-4 py-2 font-medium cursor-pointer select-none hover:text-ink" @click="sortBy('name')">
                    Name <span class="text-muted" x-text="sortIcon('name')"></span>
                </th>
                <th class="px-4 py-2 font-medium cursor-pointer select-none hover:text-ink" @click="sortBy('rank')">
                    Rank <span class="text-muted" x-text="sortIcon('rank')"></span>
                </th>
                <th class="px-4 py-2 font-medium cursor-pointer select-none hover:text-ink" @click="sortBy('lastseen')">
                    Last seen <span class="text-muted" x-text="sortIcon('lastseen')"></span>
                </th>
                <th class="px-4 py-2 font-medium text-right">Links</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($inactive as $m)
                @php
                    $cls = 'cls-' . strtoupper($m->class ?? '');
                    $days = $m->last_online_at?->diffInDays(now()) ?? null;
                @endphp
                <tr class="border-t border-line" data-row>
                    <td class="px-4 py-2" data-sort-key="name" data-sort-value="{{ strtolower($m->name) }}">
                        <span class="inline-flex items-center gap-1.5">
                            <x-class-icon :class="$m->class" />
                            <a href="{{ route('character.show', $m->name) }}" class="{{ $cls }} hover:underline">{{ $m->name }}</a>
                        </span>
                        @if ($m->level)
                            <span class="text-muted text-xs ml-1">L{{ $m->level }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-muted" data-label="Rank" data-sort-key="rank" data-sort-value="{{ strtolower($m->rank_name ?? '') }}">{{ $m->rank_name }}</td>
                    <td class="px-4 py-2 text-muted whitespace-nowrap"
                        data-label="Last seen"
                        data-sort-key="lastseen"
                        data-sort-value="{{ $m->last_online_at?->timestamp ?? 0 }}">
                        {{ $m->last_online_at?->diffForHumans() ?? 'never' }}
                        @if ($days !== null && $days > 90)
                            <span class="text-rose-400 ml-1 text-xs">({{ floor($days) }}d)</span>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-right" data-label="Links">
                        <x-character-links :member="$m" />
                    </td>
                </tr>
            @endforeach
            <tr data-empty-message style="display:none">
                <td colspan="4" class="px-4 py-4 text-center text-muted text-xs italic">No matches.</td>
            </tr>
        </tbody>
    </table>
</x-clarity-table>
