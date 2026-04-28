<x-clarity-table
    :is-empty="$anniversaries->isEmpty()"
    searchable
    search-placeholder="Search name..."
    :count="$anniversaries->count()"
    empty="No guild anniversaries this week."
>
    <x-slot:header>
        <h2 class="text-sm font-semibold uppercase tracking-wider">Anniversaries this week</h2>
    </x-slot:header>

    <table class="w-full text-sm clarity-tabular" x-data="{ openCol: null }">
        <thead>
            <tr class="text-left text-xs uppercase tracking-wider text-muted">
                <th class="px-4 py-2 cursor-pointer select-none hover:text-ink" @click="sortBy('name')">
                    Name <span class="text-muted" x-text="sortIcon('name')"></span>
                    <x-column-explainer-toggle col="name" />
                </th>
                <th class="px-2 py-2 text-right cursor-pointer select-none hover:text-ink" @click="sortBy('years')">
                    Years <span class="text-muted" x-text="sortIcon('years')"></span>
                    <x-column-explainer-toggle col="years" />
                </th>
                <th class="px-2 py-2 cursor-pointer select-none hover:text-ink" @click="sortBy('joined')">
                    Joined <span class="text-muted" x-text="sortIcon('joined')"></span>
                    <x-column-explainer-toggle col="joined" />
                </th>
                <th class="px-4 py-2 text-right cursor-pointer select-none hover:text-ink" @click="sortBy('on')">
                    On <span class="text-muted" x-text="sortIcon('on')"></span>
                    <x-column-explainer-toggle col="on" />
                </th>
            </tr>
        </thead>
        <tbody>
            <tr x-show="openCol !== null" x-cloak class="border-t border-line bg-bg/40">
                <td colspan="4" class="px-4 py-3 text-xs text-muted leading-relaxed normal-case tracking-normal font-normal">
                    <template x-if="openCol === 'name'">
                        <div>
                            <span class="block text-ink font-semibold mb-1">Name</span>
                            Member hitting a guild-join anniversary within the next 7 days. A free
                            reason to ping them in Discord or shout them out on raid night.
                        </div>
                    </template>
                    <template x-if="openCol === 'years'">
                        <div>
                            <span class="block text-ink font-semibold mb-1">Years</span>
                            Number of years in the guild as of this anniversary (1y, 2y, 5y, 10y, etc.).
                        </div>
                    </template>
                    <template x-if="openCol === 'joined'">
                        <div>
                            <span class="block text-ink font-semibold mb-1">Joined</span>
                            Original guild-join date as recorded by GRM. Used to compute which
                            anniversary is upcoming.
                        </div>
                    </template>
                    <template x-if="openCol === 'on'">
                        <div>
                            <span class="block text-ink font-semibold mb-1">On</span>
                            Date the anniversary falls on this week. The row disappears once that
                            date passes.
                        </div>
                    </template>
                </td>
            </tr>
            @foreach ($anniversaries as $event)
                @php
                    $m = $event->member;
                    $years = (int) ($event->payload_json['years'] ?? 0);
                    $cls = 'cls-' . strtoupper($m->class ?? '');
                @endphp
                <tr class="border-t border-line" data-row>
                    <td class="px-4 py-2" data-sort-key="name" data-sort-value="{{ strtolower($m->name) }}">
                        <span class="inline-flex items-center gap-1.5">
                            <x-class-icon :class="$m->class" />
                            <span class="{{ $cls }} font-medium">{{ $m->name }}</span>
                        </span>
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
