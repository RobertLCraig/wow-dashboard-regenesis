<x-clarity-table
    :is-empty="$bans->isEmpty()"
    searchable
    search-placeholder="Search name or reason..."
    :count="$bans->count()"
    empty="No bans on record."
>
    <x-slot:header>
        <h2 class="text-sm font-semibold uppercase tracking-wider flex items-center gap-2">
            <span>Ban list</span>
            <x-explainer-toggle />
        </h2>
    </x-slot:header>

    <x-slot:explainer>
        <x-explainer-panel title="Ban list">
            Names recorded as banned, either flagged in the GRM addon or via the
            dashboard. Reason and date shown when set. Use as a quick reference before
            re-inviting someone you don't recognise, or before vouching for a returning
            player. Bans without a reason should be back-filled when you spot them.
        </x-explainer-panel>
    </x-slot:explainer>

    <table class="w-full text-sm clarity-tabular">
        <thead>
            <tr class="text-left text-xs uppercase tracking-wider text-muted">
                <th class="px-4 py-2 cursor-pointer select-none hover:text-ink" @click="sortBy('name')">
                    Name <span class="text-muted" x-text="sortIcon('name')"></span>
                </th>
                <th class="px-2 py-2">Reason</th>
                <th class="px-4 py-2 text-right cursor-pointer select-none hover:text-ink" @click="sortBy('when')">
                    When <span class="text-muted" x-text="sortIcon('when')"></span>
                </th>
            </tr>
        </thead>
        <tbody>
            @foreach ($bans as $b)
                @php $cls = 'cls-' . strtoupper($b->class ?? ''); @endphp
                <tr class="border-t border-line" data-row>
                    <td class="px-4 py-2" data-sort-key="name" data-sort-value="{{ strtolower($b->name) }}">
                        <span class="{{ $cls }} font-medium">{{ $b->name }}</span>
                        <span class="text-muted text-xs ml-1">L{{ $b->level }} {{ $b->rank_name }}</span>
                    </td>
                    <td class="px-2 py-2 text-xs" data-label="Reason">
                        @if ($b->reason_banned)
                            <span class="text-rose-300">{{ $b->reason_banned }}</span>
                        @else
                            <span class="italic text-muted">no reason recorded</span>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-right text-xs text-muted whitespace-nowrap"
                        data-label="Banned"
                        data-sort-key="when"
                        data-sort-value="{{ $b->banned_at?->timestamp ?? 0 }}">
                        {{ $b->banned_at?->diffForHumans() ?? '-' }}
                    </td>
                </tr>
            @endforeach
            <tr data-empty-message style="display:none">
                <td colspan="3" class="px-4 py-4 text-center text-muted text-xs italic">No bans match.</td>
            </tr>
        </tbody>
    </table>
</x-clarity-table>
