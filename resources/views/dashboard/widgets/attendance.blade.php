<section class="bg-panel border border-line rounded-lg overflow-hidden">
    <div x-data="{ explain: false }">
        <header class="px-4 py-3 border-b border-line flex items-center justify-between">
            <h2 class="text-sm font-semibold uppercase tracking-wider flex items-center gap-2">
                <span>Raid attendance</span>
                <x-explainer-toggle />
            </h2>
            <span class="text-xs text-muted">
                @if ($attendance['captured_at'])
                    snapshot {{ $attendance['captured_at']->diffForHumans() }}
                @else
                    no data yet
                @endif
            </span>
        </header>
        <x-explainer-panel title="Raid attendance">
            Per-member attendance percentage from Raid-Helper signups. The fraction is
            raids attended over raids signed up to (or eligible for, depending on how
            the sync is configured). Refreshes when the attendance sync runs. Use it to
            back up promote / demote conversations with hard numbers and to spot
            reliability problems before invite night rather than after a wipe. Greens
            are 80%+, ambers 50-79%, reds below 50%.
        </x-explainer-panel>
    </div>
    @if (empty($attendance['rows']))
        <div class="p-8 text-center text-muted text-sm">
            No attendance pulled yet. Run <code class="text-ink">php artisan raidhelper:sync-attendance</code>
            once <code class="text-ink">RAID_HELPER_API_KEY</code> is set.
        </div>
    @else
    <ul class="divide-y divide-line max-h-[400px] overflow-y-auto">
        @foreach ($attendance['rows'] as $row)
            @php
                $pct = (float) $row->attendance_pct;
                $tone = $pct >= 80 ? 'text-emerald-300' : ($pct >= 50 ? 'text-amber-300' : 'text-rose-300');
            @endphp
            <li class="px-4 py-2 text-sm flex items-center justify-between gap-3">
                <span class="truncate">{{ $row->member_name }}</span>
                <span class="flex items-center gap-2 text-xs whitespace-nowrap">
                    <span class="text-muted">{{ $row->attended_count }}/{{ $row->total_count }}</span>
                    <span class="{{ $tone }} font-mono">{{ number_format($pct, 1) }}%</span>
                </span>
            </li>
        @endforeach
    </ul>
    @endif
</section>
