@php
    /**
     * Headline numbers for one team: roster count, best raid summary
     * across all members, average ilvl, top RIO score, top weekly key.
     * Variant of dashboard.widgets.team-progression for a single-team
     * page (no team-grouping needed).
     */
    $instanceLabel = function (?string $key): string {
        if (! $key) return '';
        return ucwords(str_replace(['-', '_'], ' ', $key));
    };
@endphp

<section class="bg-panel border border-line rounded-lg p-5">
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 text-sm">
        <div>
            <div class="text-xs uppercase tracking-wider text-muted">Members</div>
            <div class="text-2xl font-semibold mt-0.5">{{ $raidSummary['count'] }}</div>
            @if ($raidSummary['count'] > 0 && $raidSummary['with_data'] < $raidSummary['count'])
                <div class="text-xs text-muted mt-0.5">
                    {{ $raidSummary['with_data'] }}/{{ $raidSummary['count'] }} on RIO
                </div>
            @endif
        </div>

        <div class="md:col-span-2">
            <div class="text-xs uppercase tracking-wider text-muted">Best progression</div>
            <div class="text-lg font-mono mt-0.5">
                {{ $raidSummary['best_summary'] ?? '-' }}
            </div>
            @if ($raidSummary['best_key'])
                <div class="text-xs text-muted mt-0.5">{{ $instanceLabel($raidSummary['best_key']) }}</div>
            @endif
        </div>

        <div>
            <div class="text-xs uppercase tracking-wider text-muted">Avg ilvl</div>
            <div class="text-2xl font-semibold mt-0.5">{{ $raidSummary['avg_ilvl'] ?? '-' }}</div>
        </div>

        <div>
            <div class="text-xs uppercase tracking-wider text-muted">Top RIO / key</div>
            <div class="text-lg font-mono mt-0.5">
                {{ $raidSummary['top_rio'] !== null ? number_format($raidSummary['top_rio'], 0) : '-' }}
                <span class="text-muted">/</span>
                {{ $raidSummary['top_key'] !== null ? '+' . $raidSummary['top_key'] : '-' }}
            </div>
        </div>
    </div>
</section>
