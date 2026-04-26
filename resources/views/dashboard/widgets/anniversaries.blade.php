<x-clarity-table
    :is-empty="$anniversaries->isEmpty()"
    searchable
    search-placeholder="Search name..."
    :count="$anniversaries->count()"
    empty="No guild anniversaries this week."
>
    <x-slot:header>
        <h2 class="text-sm font-semibold uppercase tracking-wider flex items-center gap-2">
            <span>Anniversaries this week</span>
            <x-explainer-toggle />
        </h2>
    </x-slot:header>

    <x-slot:explainer>
        <x-explainer-panel title="Anniversaries this week">
            Members hitting a guild-join anniversary (1y, 2y, 5y, etc.) within the next
            7 days, calculated from GRM's recorded join date. A free reason to ping
            someone in Discord, shout them out on raid night, or just notice that a
            long-timer is coming up on their decade. Disappears once the date passes.
        </x-explainer-panel>
    </x-slot:explainer>

    <table class="w-full text-sm clarity-tabular">
        <thead>
            <tr class="text-left text-xs uppercase tracking-wider text-muted">
                <th class="px-4 py-2 cursor-pointer select-none hover:text-ink" @click="sortBy('name')">
                    Name <span class="text-muted" x-text="sortIcon('name')"></span>
                </th>
                <th class="px-2 py-2 text-right cursor-pointer select-none hover:text-ink" @click="sortBy('years')">
                    Years <span class="text-muted" x-text="sortIcon('years')"></span>
                </th>
                <th class="px-2 py-2 cursor-pointer select-none hover:text-ink" @click="sortBy('joined')">
                    Joined <span class="text-muted" x-text="sortIcon('joined')"></span>
                </th>
                <th class="px-4 py-2 text-right cursor-pointer select-none hover:text-ink" @click="sortBy('on')">
                    On <span class="text-muted" x-text="sortIcon('on')"></span>
                </th>
            </tr>
        </thead>
        <tbody>
            @foreach ($anniversaries as $event)
                @php
                    $m = $event->member;
                    $years = (int) ($event->payload_json['years'] ?? 0);
                    $cls = 'cls-' . strtoupper($m->class ?? '');
                @endphp
                <tr class="border-t border-line" data-row>
                    <td class="px-4 py-2" data-sort-key="name" data-sort-value="{{ strtolower($m->name) }}">
                        <span class="{{ $cls }} font-medium">{{ $m->name }}</span>
                    </td>
                    <td class="px-2 py-2 font-mono text-amber-300 text-right text-xs"
                        data-label="Years"
                        data-sort-key="years"
                        data-sort-value="{{ $years }}">{{ $years }}y</td>
                    <td class="px-2 py-2 text-muted text-xs"
                        data-label="Joined"
                        data-sort-key="joined"
                        data-sort-value="{{ $m->join_date?->timestamp ?? 0 }}">
                        {{ $m->join_date?->format('d M Y') ?? '-' }}
                    </td>
                    <td class="px-4 py-2 text-muted text-xs text-right whitespace-nowrap"
                        data-label="Falls on"
                        data-sort-key="on"
                        data-sort-value="{{ $event->occurred_at->timestamp }}">
                        {{ $event->occurred_at->format('D d M') }}
                    </td>
                </tr>
            @endforeach
            <tr data-empty-message style="display:none">
                <td colspan="4" class="px-4 py-4 text-center text-muted text-xs italic">No anniversaries match.</td>
            </tr>
        </tbody>
    </table>
</x-clarity-table>
