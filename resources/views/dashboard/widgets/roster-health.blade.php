@php
    $delta = $health['delta_7d'] ?? 0;
    $deltaClass = $delta > 0 ? 'text-emerald-400' : ($delta < 0 ? 'text-rose-400' : 'text-muted');
    $deltaLabel = ($delta > 0 ? '+' : '') . $delta;
@endphp
<section class="grid grid-cols-2 md:grid-cols-4 gap-4">
    <div class="bg-panel border border-line rounded-lg p-4">
        <div class="text-xs uppercase tracking-wider text-muted">Active members</div>
        <div class="text-3xl font-semibold mt-1">{{ number_format($health['active']) }}</div>
        <div class="text-xs mt-1 {{ $deltaClass }}">
            {{ $deltaLabel }} over last 7d
            <span class="text-muted">({{ $health['joiners_7d'] }} in / {{ $health['leavers_7d'] }} out)</span>
        </div>
    </div>

    <div class="bg-panel border border-line rounded-lg p-4">
        <div class="text-xs uppercase tracking-wider text-muted">Retention</div>
        <div class="text-3xl font-semibold mt-1">
            @if ($health['retention_pct'] === null) - @else {{ $health['retention_pct'] }}% @endif
        </div>
        <div class="text-xs text-muted mt-1">Active members seen in last 30d</div>
    </div>

    <div class="bg-panel border border-line rounded-lg p-4">
        <div class="text-xs uppercase tracking-wider text-muted">Inactive &gt; 30d</div>
        <div class="text-3xl font-semibold mt-1 {{ $health['inactive_count'] > 0 ? 'text-amber-400' : '' }}">{{ $health['inactive_count'] }}</div>
        <div class="text-xs text-muted mt-1">Of {{ $health['active'] }} active</div>
    </div>

    <div class="bg-panel border border-line rounded-lg p-4">
        <div class="text-xs uppercase tracking-wider text-muted">Avg days idle</div>
        <div class="text-3xl font-semibold mt-1">
            @if ($health['avg_days_since_online'] === null) - @else {{ $health['avg_days_since_online'] }} @endif
        </div>
        <div class="text-xs text-muted mt-1">
            avg level
            @if ($health['avg_level'] === null) - @else {{ $health['avg_level'] }} @endif
        </div>
    </div>
</section>
