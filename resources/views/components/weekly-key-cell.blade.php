@props([
    'snap' => null,
    'align' => 'right',
])

{{--
    Weekly key cell. Renders the headline +N (coloured by tier) plus the
    completed-on day, and on click opens a popover listing every key the
    character has run this reset (sourced from raider.io's
    mythic_plus_weekly_highest_level_runs blob in raw_json). Falls back
    to a plain dash when the member has no key (or no snapshot).

    The wrapping <td>/<div> is the caller's job (so each caller can keep
    its own data-label / data-sort-value / grid placement). Position
    context for the popover lives inside the component on its own
    `relative` wrapper.
--}}

@php
    $level = (int) ($snap?->mplus_keystone ?? 0);
    $weeklyRuns = collect($snap?->raw_json['mythic_plus_weekly_highest_level_runs'] ?? [])
        ->filter(fn ($r) => is_array($r) && isset($r['mythic_level']))
        ->sortByDesc(fn ($r) => (int) ($r['mythic_level'] ?? 0))
        ->values();
    $topRun = $weeklyRuns->first();
    $topCompleted = null;
    if (is_array($topRun) && is_string($topRun['completed_at'] ?? null)) {
        try {
            $topCompleted = \Carbon\CarbonImmutable::parse($topRun['completed_at']);
        } catch (\Throwable) {
        }
    }
    $weeklyTone = $level >= 20 ? 'text-amber-300' : ($level >= 15 ? 'text-emerald-300' : 'text-ink');
    $popoverEdge = $align === 'right' ? 'right-0' : 'left-0';
    $wrapperAlign = $align === 'right' ? 'text-right' : 'text-left';
@endphp

@if ($snap?->mplus_keystone === null)
    <span class="font-mono text-muted">-</span>
@else
    <div class="relative inline-block {{ $wrapperAlign }}"
         x-data="{ open: false }"
         @click.outside="open = false">
        <button type="button"
                @click="open = !open"
                class="font-mono inline-flex items-center gap-1.5 hover:text-accent transition-colors {{ $weeklyTone }}"
                @if ($weeklyRuns->count() <= 1 && ! $topCompleted) disabled @endif>
            <span>+{{ $snap->mplus_keystone }}</span>
            @if ($topCompleted)
                <span class="text-[10px] text-muted" title="{{ $topCompleted->toDayDateTimeString() }}">{{ $topCompleted->format('D') }}</span>
            @endif
            @if ($weeklyRuns->count() > 1)
                <span class="text-[10px] text-muted" x-text="open ? '▾' : '▸'"></span>
            @endif
        </button>
        @if ($weeklyRuns->isNotEmpty())
            <div x-show="open" x-cloak x-transition.opacity
                 class="absolute {{ $popoverEdge }} top-full mt-1 z-20 min-w-[220px] bg-panel border border-line rounded-md shadow-lg p-2 text-left">
                <div class="text-[10px] uppercase tracking-wider text-muted mb-1.5 px-1">This week's runs</div>
                <ul class="space-y-1 text-xs font-mono">
                    @foreach ($weeklyRuns as $run)
                        @php
                            $runLevel = (int) ($run['mythic_level'] ?? 0);
                            $upg = (int) ($run['num_keystone_upgrades'] ?? 0);
                            $short = $run['short_name'] ?? ($run['dungeon'] ?? '?');
                            $when = null;
                            if (is_string($run['completed_at'] ?? null)) {
                                try {
                                    $when = \Carbon\CarbonImmutable::parse($run['completed_at']);
                                } catch (\Throwable) {
                                }
                            }
                            $rowTone = match ($upg) {
                                3 => 'text-amber-300',
                                2, 1 => 'text-emerald-300',
                                default => 'text-muted',
                            };
                            $upgMarks = $upg > 0 ? str_repeat('+', $upg) : '';
                        @endphp
                        <li class="flex items-center justify-between gap-3 px-1 py-0.5 rounded hover:bg-bg/60">
                            <span class="{{ $rowTone }}">+{{ $runLevel }}{{ $upgMarks ? ' ' . $upgMarks : '' }}</span>
                            <span class="text-ink truncate">{{ $short }}</span>
                            <span class="text-muted text-[10px]" @if ($when) title="{{ $when->toDayDateTimeString() }}" @endif>
                                {{ $when?->format('D H:i') ?? '-' }}
                            </span>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-1.5 pt-1.5 border-t border-line/60 text-[10px] text-muted px-1">
                    Untimed = grey, timed = green, +3 = amber
                </div>
            </div>
        @endif
    </div>
@endif
