<x-clarity-table
    :is-empty="empty($attendance['rows'])"
    searchable
    search-placeholder="Search member..."
    :meta="$attendance['captured_at'] ? 'snapshot ' . $attendance['captured_at']->diffForHumans() : 'no data yet'"
    empty='No attendance pulled yet. Run <code class="text-ink">php artisan raidhelper:sync-attendance</code> once <code class="text-ink">RAID_HELPER_API_KEY</code> is set.'
>
    <x-slot:header>
        <h2 class="text-sm font-semibold uppercase tracking-wider flex items-center gap-2">
            <span>Raid attendance</span>
            <x-explainer-toggle />
        </h2>
    </x-slot:header>

    <x-slot:explainer>
        <x-explainer-panel title="Raid attendance">
            Per-member attendance percentage from Raid-Helper signups. The fraction is
            raids attended over raids signed up to (or eligible for, depending on how
            the sync is configured). Refreshes when the attendance sync runs. Use it to
            back up promote / demote conversations with hard numbers and to spot
            reliability problems before invite night rather than after a wipe. Greens
            are 80%+, ambers 50-79%, reds below 50%.
        </x-explainer-panel>
    </x-slot:explainer>

    <table class="w-full text-sm clarity-tabular">
        <thead>
            <tr class="text-left text-xs uppercase tracking-wider text-muted">
                <th class="px-4 py-2 cursor-pointer select-none hover:text-ink" @click="sortBy('name')">
                    Member <span class="text-muted" x-text="sortIcon('name')"></span>
                </th>
                <th class="px-2 py-2 text-right cursor-pointer select-none hover:text-ink" @click="sortBy('attended')">
                    Raids <span class="text-muted" x-text="sortIcon('attended')"></span>
                </th>
                <th class="px-4 py-2 text-right cursor-pointer select-none hover:text-ink" @click="sortBy('pct')">
                    % <span class="text-muted" x-text="sortIcon('pct')"></span>
                </th>
            </tr>
        </thead>
        <tbody>
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
