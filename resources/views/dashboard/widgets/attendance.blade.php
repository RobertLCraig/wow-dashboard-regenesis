<section class="bg-panel border border-line rounded-lg overflow-hidden">
    <header class="px-4 py-3 border-b border-line flex items-center justify-between">
        <h2 class="text-sm font-semibold uppercase tracking-wider">Raid attendance</h2>
        <span class="text-xs text-muted">
            @if ($attendance['captured_at'])
                snapshot {{ $attendance['captured_at']->diffForHumans() }}
            @else
                no data yet
            @endif
        </span>
    </header>
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
