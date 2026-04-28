@php
    $typeColours = [
        'PROMOTED' => 'bg-emerald-500/20 text-emerald-300',
        'DEMOTED' => 'bg-rose-500/20 text-rose-300',
        'JOINED' => 'bg-blue-500/20 text-blue-300',
        'REJOINED' => 'bg-sky-500/20 text-sky-300',
        'REJOINED_BANNED' => 'bg-red-500/20 text-red-300',
        'LEFT' => 'bg-slate-500/20 text-slate-300',
        'KICKED' => 'bg-orange-500/20 text-orange-300',
        'BANNED' => 'bg-red-500/20 text-red-300',
        'PUBLIC_NOTE' => 'bg-violet-500/20 text-violet-300',
        'OFFICER_NOTE' => 'bg-purple-500/20 text-purple-300',
        'LEVEL_UP' => 'bg-yellow-500/20 text-yellow-300',
        'RANK_RENAME' => 'bg-indigo-500/20 text-indigo-300',
        'NAME_CHANGE' => 'bg-pink-500/20 text-pink-300',
        'INACTIVE_RETURN' => 'bg-teal-500/20 text-teal-300',
        'EVENT' => 'bg-fuchsia-500/20 text-fuchsia-300',
        'EVENT_BIRTHDAY' => 'bg-fuchsia-500/20 text-fuchsia-300',
        'EVENT_ANNIVERSARY' => 'bg-amber-500/20 text-amber-300',
        'HARDCORE_DEATH' => 'bg-red-500/20 text-red-300',
        'RECOMMEND_KICK' => 'bg-orange-500/10 text-orange-200',
        'RECOMMEND_PROMOTE' => 'bg-emerald-500/10 text-emerald-200',
        'RECOMMEND_DEMOTE' => 'bg-rose-500/10 text-rose-200',
        'RECOMMEND_SPECIAL' => 'bg-violet-500/10 text-violet-200',
    ];

    $availableTypes = $timeline
        ->pluck('type_name')
        ->filter()
        ->unique()
        ->sort()
        ->values();
@endphp
<x-clarity-table
    :is-empty="$timeline->isEmpty()"
    searchable
    search-placeholder="Search activity..."
    :count="$timeline->count() . ' events'"
    empty="No log entries yet."
>
    <x-slot:header>
        <h2 class="text-sm font-semibold uppercase tracking-wider">Recent activity</h2>
    </x-slot:header>

    <x-slot:filters>
        <select x-model="filters.type"
                class="bg-bg border border-line rounded px-2 py-1 text-xs text-ink">
            <option value="">All types</option>
            @foreach ($availableTypes as $t)
                <option value="{{ $t }}">{{ $t }}</option>
            @endforeach
        </select>
    </x-slot:filters>

    <table class="w-full text-sm clarity-tabular" x-data="{ openCol: null }">
        <thead>
            <tr class="text-left text-xs uppercase tracking-wider text-muted">
                <th class="px-4 py-2 font-medium cursor-pointer select-none hover:text-ink whitespace-nowrap" @click="sortBy('when')">
                    When <span class="text-muted" x-text="sortIcon('when')"></span>
                    <x-column-explainer-toggle col="when" />
                </th>
                <th class="px-2 py-2 font-medium cursor-pointer select-none hover:text-ink whitespace-nowrap" @click="sortBy('type')">
                    Type <span class="text-muted" x-text="sortIcon('type')"></span>
                    <x-column-explainer-toggle col="type" />
                </th>
                <th class="px-4 py-2 font-medium cursor-pointer select-none hover:text-ink" @click="sortBy('message')">
                    Message <span class="text-muted" x-text="sortIcon('message')"></span>
                    <x-column-explainer-toggle col="message" />
                </th>
            </tr>
        </thead>
        <tbody>
            <tr x-show="openCol !== null" x-cloak class="border-t border-line bg-bg/40">
                <td colspan="3" class="px-4 py-3 text-xs text-muted leading-relaxed normal-case tracking-normal font-normal">
                    <template x-if="openCol === 'when'">
                        <div>
                            <span class="block text-ink font-semibold mb-1">When</span>
                            When the event was recorded by GRM. Hover the cell for the absolute
                            timestamp; the small line underneath is the relative time. The whole
                            timeline is only as fresh as the last GRM SavedVariables sync.
                        </div>
                    </template>
                    <template x-if="openCol === 'type'">
                        <div>
                            <span class="block text-ink font-semibold mb-1">Type</span>
                            Event class: PROMOTED, DEMOTED, JOINED, LEFT, KICKED, BANNED, LEVEL_UP,
                            NAME_CHANGE, INACTIVE_RETURN, anniversary events, officer notes, and a
                            handful more. Use the dropdown above the table to filter to a single
                            type.
                        </div>
                    </template>
                    <template x-if="openCol === 'message'">
                        <div>
                            <span class="block text-ink font-semibold mb-1">Message</span>
                            Plain-text description of the event, generated from the GRM payload.
                            Searchable from the box above; sort here to group identical messages
                            (handy for spotting repeated kicks or rename storms).
                        </div>
                    </template>
                </td>
            </tr>
            @foreach ($timeline as $log)
                @php
                    $type = $log->type_name ?? 'UNKNOWN';
                    $tone = $typeColours[$type] ?? 'bg-line text-muted';
                    $message = $log->plainMessage();
                @endphp
                <tr class="border-t border-line"
                    data-row
                    data-filter-type="{{ $type }}">
                    <td class="px-4 py-2 text-muted whitespace-nowrap text-xs"
                        data-label="When"
                        data-sort-key="when"
                        data-sort-value="{{ $log->occurred_at->timestamp }}"
                        title="{{ $log->occurred_at->toDayDateTimeString() }}">
                        {{ $log->occurred_at->format("j M 'y g:ia") }}
                        <div class="text-[10px] text-muted/70">{{ $log->occurred_at->diffForHumans() }}</div>
                    </td>
                    <td class="px-2 py-2"
                        data-label="Type"
                        data-sort-key="type"
                        data-sort-value="{{ strtolower($type) }}">
                        <span class="text-xs uppercase px-2 py-0.5 rounded {{ $tone }} whitespace-nowrap">{{ $type }}</span>
                    </td>
                    <td class="px-4 py-2 text-ink"
                        data-label="Message"
                        data-sort-key="message"
                        data-sort-value="{{ strtolower($message) }}">
                        {{ $message }}
                    </td>
                </tr>
            @endforeach
            <tr data-empty-message style="display:none">
                <td colspan="3" class="px-4 py-4 text-center text-muted text-xs italic">No activity matches.</td>
            </tr>
        </tbody>
    </table>
</x-clarity-table>
