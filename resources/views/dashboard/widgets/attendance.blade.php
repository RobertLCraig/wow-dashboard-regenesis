<x-clarity-table
    :is-empty="empty($attendance['rows'])"
    searchable
    search-placeholder="Search member..."
    :meta="$attendance['captured_at'] ? 'snapshot ' . $attendance['captured_at']->diffForHumans() : 'no data yet'"
    empty='No attendance pulled yet. Run <code class="text-ink">php artisan raidhelper:sync-attendance</code> once <code class="text-ink">RAID_HELPER_API_KEY</code> is set.'
>
    <x-slot:header>
        <h2 class="text-sm font-semibold uppercase tracking-wider">Raid attendance</h2>
    </x-slot:header>

    <table class="w-full text-sm clarity-tabular" x-data="{ openCol: null }">
        <thead>
            <tr class="text-left text-xs uppercase tracking-wider text-muted">
                <th class="px-4 py-2 cursor-pointer select-none hover:text-ink" @click="sortBy('name')">
                    Member <span class="text-muted" x-text="sortIcon('name')"></span>
                    <x-column-explainer-toggle col="name" />
                </th>
                <th class="px-2 py-2 text-right cursor-pointer select-none hover:text-ink" @click="sortBy('attended')">
                    Raids <span class="text-muted" x-text="sortIcon('attended')"></span>
                    <x-column-explainer-toggle col="attended" />
                </th>
                <th class="px-4 py-2 text-right cursor-pointer select-none hover:text-ink" @click="sortBy('pct')">
                    % <span class="text-muted" x-text="sortIcon('pct')"></span>
                    <x-column-explainer-toggle col="pct" />
                </th>
            </tr>
        </thead>
        <tbody>
            <tr x-show="openCol !== null" x-cloak class="border-t border-line bg-bg/40">
                <td colspan="3" class="px-4 py-3 text-xs text-muted leading-relaxed normal-case tracking-normal font-normal">
                    <template x-if="openCol === 'name'">
                        <div>
                            <span class="block text-ink font-semibold mb-1">Member</span>
                            Member name as recorded by Raid-Helper. Refreshes whenever the
                            attendance sync runs.
                        </div>
                    </template>
                    <template x-if="openCol === 'attended'">
                        <div>
                            <span class="block text-ink font-semibold mb-1">Raids</span>
                            Raids attended over raids signed up to (or eligible for, depending on
                            how the sync is configured). Sort here to find the most or least
                            consistent attenders.
                        </div>
                    </template>
                    <template x-if="openCol === 'pct'">
                        <div>
                            <span class="block text-ink font-semibold mb-1">%</span>
                            Attendance percentage. Green at 80%+, amber 50 to 79%, red below 50%.
                            Use it to back up promote and demote conversations with hard numbers
                            and to spot reliability problems before invite night rather than after
                            a wipe.
                        </div>
                    </template>
                </td>
            </tr>
            @foreach ($attendance['rows'] as $row)
                @php
                    $pct = (float) $row->attendance_pct;
                    $tone = $pct >= 80 ? 'text-emerald-300' : ($pct >= 50 ? 'text-amber-300' : 'text-rose-300');
                @endphp
                <tr class="border-t border-line" data-row>
                    <td class="px-4 py-2 truncate" data-sort-key="name" data-sort-value="{{ strtolower($row->member_name) }}">{{ $row->member_name }}</td>
                    <td class="px-2 py-2 font-mono text-muted text-right text-xs" data-label="Raids" data-sort-key="attended" data-sort-value="{{ $row->attended_count }}">
                        {{ $row->attended_count }}/{{ $row->total_count }}
                    </td>
                    <td class="px-4 py-2 font-mono text-right text-xs" data-label="Attendance" data-sort-key="pct" data-sort-value="{{ $pct }}">
                        <span class="{{ $tone }}">{{ number_format($pct, 1) }}%</span>
                    </td>
                </tr>
            @endforeach
            <tr data-empty-message style="display:none">
                <td colspan="3" class="px-4 py-4 text-center text-muted text-xs italic">No members match.</td>
            </tr>
        </tbody>
    </table>
</x-clarity-table>
