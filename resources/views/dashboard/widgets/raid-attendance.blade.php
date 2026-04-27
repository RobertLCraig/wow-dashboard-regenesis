<section class="bg-panel border border-line rounded-lg overflow-hidden">
    <div x-data="{ explain: false }">
        <header class="px-4 py-3 border-b border-line flex items-center justify-between gap-3">
            <h2 class="text-sm font-semibold uppercase tracking-wider flex items-center gap-2">
                <span>Raid attendance</span>
                <x-explainer-toggle />
            </h2>
            <span class="text-xs text-muted whitespace-nowrap">{{ $raidAttendance->count() }} {{ \Illuminate\Support\Str::plural('raid', $raidAttendance->count()) }}</span>
        </header>
        <x-explainer-panel title="Raid attendance">
            For each recent raid, compares Raid-Helper signups against the
            characters that actually appear in WCL parses for that night.
            Anyone who said they were coming but didn't zone in shows up
            as a no-show. Players who swapped to an alt are counted as
            showing up, with a small "via {alt}" note. Raids without a
            matching WCL report yet are flagged "WCL pull pending".
        </x-explainer-panel>
    </div>

    @if ($raidAttendance->isEmpty())
        <div class="p-8 text-center text-muted text-sm">No raids in the last 14 days.</div>
    @else
        <ul class="divide-y divide-line">
            @foreach ($raidAttendance as $row)
                @php
                    $event = $row['event'];
                    $report = $row['wcl_report'];
                    $shown = $row['showed_up_count'];
                    $signed = $row['signed_up_count'];
                    $missing = $signed - $shown;
                    $hasNoWcl = $report === null;
                @endphp
                <li class="px-4 py-3" x-data="{ open: false }">
                    <button type="button" @click="open = !open"
                            class="w-full text-left flex items-center justify-between gap-3 text-sm">
                        <span class="flex items-center gap-2 min-w-0">
                            <span class="text-muted text-xs uppercase tracking-wider whitespace-nowrap">
                                {{ $event->starts_at?->format('D d M') }}
                            </span>
                            <span class="font-medium truncate">{{ $event->title }}</span>
                        </span>
                        <span class="flex items-center gap-3 text-xs whitespace-nowrap">
                            @if ($hasNoWcl)
                                <span class="text-muted italic">WCL pull pending</span>
                            @else
                                <span class="text-muted">{{ $shown }} / {{ $signed }} showed</span>
                                @if ($missing > 0)
                                    <span class="px-1.5 py-0.5 rounded border border-rose-700/50 text-rose-300 text-[10px] uppercase tracking-wider">
                                        {{ $missing }} no-{{ \Illuminate\Support\Str::plural('show', $missing) }}
                                    </span>
                                @endif
                            @endif
                            <span class="text-muted text-xs" x-text="open ? '−' : '+'"></span>
                        </span>
                    </button>

                    <div x-show="open" x-cloak class="mt-3 ml-2 pl-3 border-l border-line space-y-3 text-xs">
                        @if ($report)
                            <div class="text-muted">
                                <a href="{{ $report->jumpUrl() }}" target="_blank" rel="noopener noreferrer" class="hover:text-ink underline">
                                    WCL report {{ $report->code }}
                                </a>
                                @if ($report->start_time)
                                    <span class="ml-1">started {{ $report->start_time->format('H:i') }}</span>
                                @endif
                            </div>
                        @endif

                        @if (! empty($row['no_shows']))
                            <div>
                                <div class="text-muted uppercase tracking-wider mb-1">No-shows</div>
                                <ul class="space-y-1">
                                    @foreach ($row['no_shows'] as $miss)
                                        @php $cls = 'cls-' . strtoupper($miss['class'] ?? ''); @endphp
                                        <li class="flex items-center gap-1.5">
                                            <x-class-icon :class="$miss['class']" :size="14" />
                                            <span class="{{ $cls }}">{{ $miss['name'] }}</span>
                                            @if (! empty($miss['role']))
                                                <span class="text-muted">- {{ $miss['role'] }}</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if (! empty($row['showed_via_alts']))
                            <div>
                                <div class="text-muted uppercase tracking-wider mb-1">Showed via alt</div>
                                <ul class="space-y-1">
                                    @foreach ($row['showed_via_alts'] as $swap)
                                        <li class="text-muted">
                                            {{ $swap['signup_name'] }}
                                            <span class="text-muted/70">→</span>
                                            <span class="text-ink">{{ $swap['alt_name'] }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if (empty($row['no_shows']) && empty($row['showed_via_alts']) && ! $hasNoWcl)
                            <div class="text-muted italic">Full attendance.</div>
                        @endif

                        @if ($hasNoWcl)
                            <div class="text-muted italic">No matching WCL report yet. Trigger a sync from /admin/sync to refresh.</div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>
