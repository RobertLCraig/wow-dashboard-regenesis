<x-clarity-table
    :is-empty="$bans->isEmpty()"
    searchable
    search-placeholder="Search name or reason..."
    :count="$bans->count()"
    empty="No bans on record."
>
    <x-slot:header>
        <h2 class="text-sm font-semibold uppercase tracking-wider">Ban list</h2>
    </x-slot:header>

    <table class="w-full text-sm clarity-tabular" x-data="{ openCol: null }">
        <thead>
            <tr class="text-left text-xs uppercase tracking-wider text-muted">
                <th class="px-4 py-2 cursor-pointer select-none hover:text-ink" @click="sortBy('name')">
                    Name <span class="text-muted" x-text="sortIcon('name')"></span>
                    <x-column-explainer-toggle col="name" />
                </th>
                <th class="px-2 py-2">
                    Reason
                    <x-column-explainer-toggle col="reason" />
                </th>
                <th class="px-4 py-2 text-right cursor-pointer select-none hover:text-ink" @click="sortBy('when')">
                    When <span class="text-muted" x-text="sortIcon('when')"></span>
                    <x-column-explainer-toggle col="when" />
                </th>
            </tr>
        </thead>
        <tbody>
            <tr x-show="openCol !== null" x-cloak class="border-t border-line bg-bg/40">
                <td colspan="3" class="px-4 py-3 text-xs text-muted leading-relaxed normal-case tracking-normal font-normal">
                    <template x-if="openCol === 'name'">
                        <div>
                            <span class="block text-ink font-semibold mb-1">Name</span>
                            Character recorded as banned, either flagged in the GRM addon or via the
                            dashboard. Class colour, level and last known rank shown next to the name.
                            Use as a quick reference before re-inviting someone you don't recognise.
                        </div>
                    </template>
                    <template x-if="openCol === 'reason'">
                        <div>
                            <span class="block text-ink font-semibold mb-1">Reason</span>
                            Reason the ban was applied. Empty entries should be back-filled when you
                            spot them, since "no reason recorded" is hard to defend if the player
                            asks why.
                        </div>
                    </template>
                    <template x-if="openCol === 'when'">
                        <div>
                            <span class="block text-ink font-semibold mb-1">When</span>
                            When the ban was applied. Shown relative to now; sort here to see the
                            most recent bans first.
                        </div>
                    </template>
                </td>
            </tr>
            @foreach ($bans as $b)
                @php $cls = 'cls-' . strtoupper($b->class ?? ''); @endphp
                <tr class="border-t border-line" data-row>
                    <td class="px-4 py-2" data-sort-key="name" data-sort-value="{{ strtolower($b->name) }}">
                        <span class="inline-flex items-center gap-1.5">
                            <x-class-icon :class="$b->class" />
                            <span class="{{ $cls }} font-medium">{{ $b->name }}</span>
                        </span>
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
