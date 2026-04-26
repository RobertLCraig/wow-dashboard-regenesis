@php
    /**
     * Highest M+ key completed this period per character. Sourced from
     * wowaudit historical_data.dungeons_done[].level - we already
     * pre-computed the max into MemberSnapshot.mplus_keystone, so this
     * is a straight read.
     */
    $rows = collect($wowaudit['members'])
        ->filter(fn ($s) => $s->mplus_keystone !== null && $s->mplus_keystone > 0)
        ->sortByDesc('mplus_keystone')
        ->take(20)
        ->values();
@endphp
<x-clarity-table
    :is-empty="$rows->isEmpty()"
    searchable
    search-placeholder="Search character..."
    :count="'top ' . $rows->count()"
    empty="No M+ data yet (or no one has run a key this week)."
>
    <x-slot:header>
        <h2 class="text-sm font-semibold uppercase tracking-wider flex items-center gap-2">
            <span>Mythic+ this week</span>
            <x-explainer-toggle />
        </h2>
    </x-slot:header>

    <x-slot:explainer>
        <x-explainer-panel title="Mythic+ this week">
            Top 20 keystone runs this reset, sorted by key level. Each row is the
            highest key that character has timed (or completed) since weekly reset.
            Sourced from wowaudit's dungeons_done history. Useful for finding M+ groups,
            picking trial keys to push, and spotting raiders who haven't done their
            weekly chores. +20 or above goes amber, +15-19 green.
        </x-explainer-panel>
    </x-slot:explainer>

    <table class="w-full text-sm clarity-tabular">
        <thead>
            <tr class="text-left text-xs uppercase tracking-wider text-muted">
                <th class="px-4 py-2 w-8 text-right cursor-pointer select-none hover:text-ink" @click="sortBy('rank')">
                    # <span class="text-muted" x-text="sortIcon('rank')"></span>
                </th>
                <th class="px-2 py-2 cursor-pointer select-none hover:text-ink" @click="sortBy('character')">
                    Character <span class="text-muted" x-text="sortIcon('character')"></span>
                </th>
                <th class="px-4 py-2 text-right cursor-pointer select-none hover:text-ink" @click="sortBy('key')">
                    Key <span class="text-muted" x-text="sortIcon('key')"></span>
                </th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $i => $snap)
                @php
                    $cls = 'cls-' . strtoupper($snap->member->class ?? '');
                    $level = (int) $snap->mplus_keystone;
                    $tone = $level >= 20 ? 'text-amber-300' : ($level >= 15 ? 'text-emerald-300' : 'text-muted');
                @endphp
                <tr class="border-t border-line" data-row>
                    <td class="px-4 py-2 font-mono text-muted text-right" data-sort-key="rank" data-sort-value="{{ $i + 1 }}">{{ $i + 1 }}</td>
                    <td class="px-2 py-2 truncate max-w-[260px]" data-sort-key="character" data-sort-value="{{ strtolower($snap->member->name) }}">
                        <span class="inline-flex items-center gap-1.5">
                            <x-class-icon :class="$snap->member->class" />
                            <span class="{{ $cls }}">{{ $snap->member->name }}</span>
                        </span>
                    </td>
                    <td class="px-4 py-2 font-mono text-right text-xs" data-label="Key" data-sort-key="key" data-sort-value="{{ $level }}">
                        <span class="{{ $tone }}">+{{ $level }}</span>
                    </td>
                </tr>
            @endforeach
            <tr data-empty-message style="display:none">
                <td colspan="3" class="px-4 py-4 text-center text-muted text-xs italic">No characters match.</td>
            </tr>
        </tbody>
    </table>
</x-clarity-table>
