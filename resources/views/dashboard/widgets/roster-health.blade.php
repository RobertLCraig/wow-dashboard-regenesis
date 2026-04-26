@php
    $delta = $health['delta_7d'] ?? 0;
    $deltaClass = $delta > 0 ? 'text-emerald-400' : ($delta < 0 ? 'text-rose-400' : 'text-muted');
    $deltaLabel = ($delta > 0 ? '+' : '') . $delta;
@endphp
<section class="grid grid-cols-2 md:grid-cols-4 gap-4">
    <div class="bg-panel border border-line rounded-lg p-4" x-data="{ explain: false }">
        <div class="text-xs uppercase tracking-wider text-muted flex items-center gap-1.5">
            <span>Active members</span>
            <x-explainer-toggle />
        </div>
        <div class="text-3xl font-semibold mt-1">{{ number_format($health['active']) }}</div>
        <div class="text-xs mt-1 {{ $deltaClass }}">
            {{ $deltaLabel }} over last 7d
            <span class="text-muted">({{ $health['joiners_7d'] }} in / {{ $health['leavers_7d'] }} out)</span>
        </div>
        <x-explainer-panel variant="card" title="Active members">
            Headcount of the roster excluding anyone the GRM addon has marked inactive.
            The line below is the net change over the last 7 days, calculated from
            JOINED / LEFT / KICKED log events. Watch for sudden drops (mass leave) or a
            slow bleed (more leavers than joiners week after week).
        </x-explainer-panel>
    </div>

    <div class="bg-panel border border-line rounded-lg p-4" x-data="{ explain: false }">
        <div class="text-xs uppercase tracking-wider text-muted flex items-center gap-1.5">
            <span>Retention</span>
            <x-explainer-toggle />
        </div>
        <div class="text-3xl font-semibold mt-1">
            @if ($health['retention_pct'] === null) - @else {{ $health['retention_pct'] }}% @endif
        </div>
        <div class="text-xs text-muted mt-1">Active members seen in last 30d</div>
        <x-explainer-panel variant="card" title="Retention">
            Percentage of active members who have logged in at least once in the last 30
            days. A low number can mean a quiet patch, but more often signals that the
            roster is bloated with members who've quietly stopped playing and should be
            moved to the kick queue.
        </x-explainer-panel>
    </div>

    <div class="bg-panel border border-line rounded-lg p-4" x-data="{ explain: false }">
        <div class="text-xs uppercase tracking-wider text-muted flex items-center gap-1.5">
            <span>Inactive &gt; 30d</span>
            <x-explainer-toggle />
        </div>
        <div class="text-3xl font-semibold mt-1 {{ $health['inactive_count'] > 0 ? 'text-amber-400' : '' }}">{{ $health['inactive_count'] }}</div>
        <div class="text-xs text-muted mt-1">Of {{ $health['active'] }} active</div>
        <x-explainer-panel variant="card" title="Inactive over 30 days">
            Members still on the roster who have not logged in for over 30 days. These
            are the candidates for the demote / kick queue if they don't return. Cross
            reference with the Recently inactive panel below for the full list.
        </x-explainer-panel>
    </div>

    <div class="bg-panel border border-line rounded-lg p-4" x-data="{ explain: false }">
        <div class="text-xs uppercase tracking-wider text-muted flex items-center gap-1.5">
            <span>Avg days idle</span>
            <x-explainer-toggle />
        </div>
        <div class="text-3xl font-semibold mt-1">
            @if ($health['avg_days_since_online'] === null) - @else {{ $health['avg_days_since_online'] }} @endif
        </div>
        <div class="text-xs text-muted mt-1">
            avg level
            @if ($health['avg_level'] === null) - @else {{ $health['avg_level'] }} @endif
        </div>
        <x-explainer-panel variant="card" title="Average days idle">
            Mean number of days since last login across every active member. Trending
            upward week on week is the early warning that engagement is dropping even if
            headcount looks healthy. The avg level is shown for context (lots of
            low-level alts will pull both numbers around).
        </x-explainer-panel>
    </div>
</section>
