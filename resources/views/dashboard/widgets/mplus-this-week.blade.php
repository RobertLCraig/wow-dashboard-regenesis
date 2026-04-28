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
        <h2 class="text-sm font-semibold uppercase tracking-wider">Mythic+ this week</h2>
    </x-slot:header>

    <table class="w-full text-sm clarity-tabular" x-data="{ openCol: null }">
        <thead>
            <tr class="text-left text-xs uppercase tracking-wider text-muted">
                <th class="px-4 py-2 w-8 text-right cursor-pointer select-none hover:text-ink" @click="sortBy('rank')">
                    # <span class="text-muted" x-text="sortIcon('rank')"></span>
                    <x-column-explainer-toggle col="rank" />
                </th>
                <th class="px-2 py-2 cursor-pointer select-none hover:text-ink" @click="sortBy('character')">
                    Character <span class="text-muted" x-text="sortIcon('character')"></span>
                    <x-column-explainer-toggle col="character" />
                </th>
                <th class="px-4 py-2 text-right cursor-pointer select-none hover:text-ink" @click="sortBy('key')">
                    Key <span class="text-muted" x-text="sortIcon('key')"></span>
                    <x-column-explainer-toggle col="key" />
                </th>
            </tr>
        </thead>
        <tbody>
            <tr x-show="openCol !== null" x-cloak class="border-t border-line bg-bg/40">
                <td colspan="3" class="px-4 py-3 text-xs text-muted leading-relaxed normal-case tracking-normal font-normal">
                    <template x-if="openCol === 'rank'">
                        <div>
                            <span class="block text-ink font-semibold mb-1">#</span>
                            Position in this week's keystone leaderboard, top 20 only. Resets when
                            the next wowaudit sync runs after weekly reset.
                        </div>
                    </template>
                    <template x-if="openCol === 'character'">
                        <div>
                            <span class="block text-ink font-semibold mb-1">Character</span>
                            Character coloured by class. Useful for finding M+ groups, picking trial
                            keys to push, and spotting raiders who haven't done their weekly chores.
                        </div>
                    </template>
                    <template x-if="openCol === 'key'">
                        <div>
                            <span class="block text-ink font-semibold mb-1">Key</span>
                            Highest keystone this character has timed or completed since weekly reset,
                            sourced from wowaudit's dungeons_done history. +20 or above goes amber,
                            +15 to +19 green.
                        </div>
                    </template>
                </td>
            </tr>
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
