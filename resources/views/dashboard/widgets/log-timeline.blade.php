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
        <h2 class="text-sm font-semibold uppercase tracking-wider flex items-center gap-2">
            <span>Recent activity</span>
            <x-explainer-toggle />
        </h2>
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

    <x-slot:explainer>
        <x-explainer-panel title="Recent activity">
            Time-ordered guild log: promotions, demotions, joins, leaves, kicks, bans,
            level ups, name changes, returns from inactivity, anniversaries, officer
            notes. Pulled from GRM SavedVariables on each sync, so it's only as fresh as
            the last upload. Sort any column, filter by type, or search the message text
            to skim for what's happened between log-ins without scrolling Discord.
        </x-explainer-panel>
    </x-slot:explainer>

    <table class="w-full text-sm clarity-tabular">
        <thead>
            <tr class="text-left text-xs uppercase tracking-wider text-muted">
                <th class="px-4 py-2 font-medium cursor-pointer select-none hover:text-ink whitespace-nowrap" @click="sortBy('when')">
                    When <span class="text-muted" x-text="sortIcon('when')"></span>
                </th>
                <th class="px-2 py-2 font-medium cursor-pointer select-none hover:text-ink whitespace-nowrap" @click="sortBy('type')">
                    Type <span class="text-muted" x-text="sortIcon('type')"></span>
                </th>
                <th class="px-4 py-2 font-medium cursor-pointer select-none hover:text-ink" @click="sortBy('message')">
                    Message <span class="text-muted" x-text="sortIcon('message')"></span>
                </th>
            </tr>
        </thead>
        <tbody>
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
