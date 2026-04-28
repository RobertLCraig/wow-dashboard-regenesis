@php
    /**
     * Team roster: one row per active member on this team, sorted by
     * ilvl desc. Snapshot-derived columns (ilvl, RIO, weekly key, raid
     * summary) come from the latest raiderio MemberSnapshot; missing
     * means we haven't seen them on RIO yet, not that they're inactive.
     */
    $emptyMessage = 'No members on this team yet. <a href="' . route('admin.teams.index') . '" class="text-accent hover:underline">Map an in-game rank</a> and re-run the GRM sync.';
@endphp
<x-clarity-table
    :is-empty="$roster['rows']->isEmpty()"
    searchable
    search-placeholder="Search character..."
    :meta="$roster['captured_at'] ? 'raider.io ' . $roster['captured_at']->diffForHumans() : 'no raider.io data'"
    :empty="$emptyMessage"
>
    <x-slot:header>
        <h2 class="text-sm font-semibold uppercase tracking-wider">
            Roster
            <span class="text-muted text-xs font-normal normal-case ml-2">
                {{ $roster['rows']->count() }} {{ \Illuminate\Support\Str::plural('member', $roster['rows']->count()) }}
            </span>
        </h2>
    </x-slot:header>

    <table class="w-full text-sm clarity-tabular">
        <thead>
            <tr class="text-left text-xs uppercase tracking-wider text-muted">
                <th class="px-4 py-2 font-medium cursor-pointer select-none hover:text-ink" @click="sortBy('character')">
                    Character <span class="text-muted" x-text="sortIcon('character')"></span>
                </th>
                <th class="px-2 py-2 font-medium text-right cursor-pointer select-none hover:text-ink" @click="sortBy('ilvl')">
                    ilvl <span class="text-muted" x-text="sortIcon('ilvl')"></span>
                </th>
                <th class="px-2 py-2 font-medium text-right cursor-pointer select-none hover:text-ink" @click="sortBy('rio')">
                    RIO <span class="text-muted" x-text="sortIcon('rio')"></span>
                </th>
                <th class="px-2 py-2 font-medium text-right cursor-pointer select-none hover:text-ink" @click="sortBy('key')">
                    Key <span class="text-muted" x-text="sortIcon('key')"></span>
                </th>
                <th class="px-2 py-2 font-medium">Raid</th>
                <th class="px-4 py-2 font-medium text-right">Links</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($roster['rows'] as $row)
                @php
                    $m = $row['member'];
                    $snap = $row['snap'];
                    $cls = 'cls-' . strtoupper($m->class ?? '');
                    $isTrial = in_array($m->team, [
                        \App\Models\TeamMapping::TEAM_HEROIC_TRIAL,
                        \App\Models\TeamMapping::TEAM_MYTHIC_TRIAL,
                    ], true);
                @endphp
                <tr class="border-t border-line" data-row>
                    <td class="px-4 py-2 truncate max-w-[260px]" data-sort-key="character" data-sort-value="{{ strtolower($m->name) }}">
                        <span class="inline-flex items-center gap-1.5">
                            <x-class-icon :class="$m->class" />
                            <a href="{{ route('character.show', $m->name) }}" class="{{ $cls }} hover:underline">{{ $m->name }}</a>
                        </span>
                        @if ($isTrial)
                            <span class="ml-2 text-[10px] uppercase tracking-wider text-amber-300/80 border border-amber-700/40 rounded px-1 py-0.5">Trial</span>
                        @endif
                    </td>
                    <td class="px-2 py-2 font-mono text-right" data-label="ilvl" data-sort-key="ilvl" data-sort-value="{{ $snap?->ilvl ?? 0 }}">{{ $snap?->ilvl ?? '-' }}</td>
                    <td class="px-2 py-2 font-mono text-right" data-label="RIO" data-sort-key="rio" data-sort-value="{{ $snap?->mplus_score ?? 0 }}">
                        {{ $snap?->mplus_score !== null ? number_format($snap->mplus_score, 0) : '-' }}
                    </td>
                    <td class="relative px-2 py-2 text-right" data-label="Weekly key" data-sort-key="key" data-sort-value="{{ $snap?->mplus_keystone ?? 0 }}">
                        <x-weekly-key-cell :snap="$snap" />
                    </td>
                    <td class="px-2 py-2 font-mono text-xs" data-label="Raid">
                        {{ $row['raid_summary'] ?? '-' }}
                    </td>
                    <td class="px-4 py-2 text-right" data-label="Links">
                        <x-character-links :member="$m" />
                    </td>
                </tr>
            @endforeach
            <tr data-empty-message style="display:none">
                <td colspan="6" class="px-4 py-4 text-center text-muted text-xs italic">No characters match.</td>
            </tr>
        </tbody>
    </table>
</x-clarity-table>
