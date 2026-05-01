@php
    /** @var ?array $aotcGap */
    $gap = $aotcGap;
@endphp
<section class="bg-panel border border-line rounded-lg p-4">
    <header class="flex items-start justify-between gap-3 mb-3">
        <div>
            <h2 class="text-sm font-semibold uppercase tracking-wider">AOTC gap</h2>
            @if ($gap)
                <p class="text-xs text-muted mt-1">
                    {{ $gap['tier']['instance_name'] ?: 'current tier' }}
                    <span class="text-muted/70">/ {{ $gap['tier']['expansion_name'] }}</span>
                </p>
            @endif
        </div>
        @if ($gap)
            <span class="text-xs text-muted whitespace-nowrap">
                pulled {{ $gap['captured_at']?->diffForHumans() ?? 'never' }}
            </span>
        @endif
    </header>

    @if (! $gap)
        <p class="text-xs text-muted italic">
            No Blizzard raid data yet. Run blizzard:pull-raids (or wait for the daily schedule) to populate.
        </p>
    @else
        @php
            $missing = collect($gap['missing_aotc']);
            $haveCount = count($gap['has_aotc']);
            $missingCount = $missing->count();
            $ceCount = count($gap['has_ce']);
            $cohortCount = (int) ($gap['active_count'] ?? 0);
            $memberCount = (int) ($gap['active_member_count'] ?? $cohortCount);
        @endphp

        <p class="text-[11px] text-muted mb-2">
            Counts are players (alt cohorts), not characters.
            @if ($memberCount !== $cohortCount)
                <span class="text-muted/70">{{ $cohortCount }} players across {{ $memberCount }} active chars.</span>
            @endif
        </p>

        <div class="grid grid-cols-3 gap-2 mb-4 text-center">
            <div class="rounded border border-emerald-700/50 bg-emerald-950/20 px-2 py-1.5">
                <div class="text-lg font-semibold text-emerald-300">{{ $haveCount }}</div>
                <div class="text-[10px] uppercase tracking-wider text-muted">has AOTC</div>
            </div>
            <div class="rounded border border-amber-700/50 bg-amber-950/20 px-2 py-1.5">
                <div class="text-lg font-semibold text-amber-300">{{ $missingCount }}</div>
                <div class="text-[10px] uppercase tracking-wider text-muted">missing</div>
            </div>
            <div class="rounded border border-rose-700/50 bg-rose-950/20 px-2 py-1.5">
                <div class="text-lg font-semibold text-rose-300">{{ $ceCount }}</div>
                <div class="text-[10px] uppercase tracking-wider text-muted">has CE</div>
            </div>
        </div>

        @if ($missingCount === 0)
            <p class="text-xs text-emerald-300 italic">Every active player has AOTC on at least one character.</p>
        @else
            <details class="text-xs">
                <summary class="cursor-pointer text-muted hover:text-ink select-none">
                    Show {{ $missingCount }} missing
                </summary>
                <ul class="mt-2 grid grid-cols-2 md:grid-cols-3 gap-x-3 gap-y-1">
                    @foreach ($missing as $m)
                        @php
                            $cls = 'cls-' . strtoupper($m['class'] ?? '');
                            $alts = $m['alts'] ?? [];
                            $altCount = count($alts);
                        @endphp
                        <li>
                            <a href="{{ route('character.show', $m['name']) }}"
                               class="{{ $cls }} hover:underline">{{ $m['name'] }}</a>
                            @if ($altCount > 0)
                                <span class="text-muted/70" title="{{ implode(', ', $alts) }}">
                                    +{{ $altCount }}
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif
    @endif
</section>
