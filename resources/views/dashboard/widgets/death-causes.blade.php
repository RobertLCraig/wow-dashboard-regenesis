@php
    /**
     * "What killed people" rollup over the last few raid nights.
     * One block per encounter (loudest cause of death first), each
     * listing the top abilities by death count with a kill / wipe
     * split so officers can tell "wipes are still on Spike but kills
     * go clean" apart from "Spike is killing people on every pull".
     *
     * @var list<array{
     *   encounter_id:int, encounter_name:string, total_deaths:int,
     *   abilities: list<array{ability_id:?int, ability_name:string, ability_icon:?string, deaths:int, deaths_on_kills:int, deaths_on_wipes:int}>
     * }> $deathCauses
     */
    $causes = $deathCauses ?? [];
@endphp
<section class="bg-panel border border-line rounded-lg p-4">
    <header class="mb-3">
        <h2 class="text-sm font-semibold uppercase tracking-wider">What killed people</h2>
        <p class="text-xs text-muted mt-1">
            Top death causes from the last few raid nights, grouped by boss. Wipes vs kills shown side by side.
        </p>
    </header>

    @if ($causes === [])
        <p class="text-xs text-muted italic">
            No deaths recorded yet. Imports populate the table after the next wcl:pull cycle.
        </p>
    @else
        <div class="space-y-4">
            @foreach ($causes as $enc)
                <div>
                    <div class="flex items-baseline justify-between gap-2 mb-1.5">
                        <h3 class="text-xs font-semibold text-ink">{{ $enc['encounter_name'] }}</h3>
                        <span class="text-[10px] uppercase tracking-wider text-muted">
                            {{ $enc['total_deaths'] }} death{{ $enc['total_deaths'] === 1 ? '' : 's' }}
                        </span>
                    </div>
                    <ul class="space-y-1">
                        @foreach ($enc['abilities'] as $a)
                            <li class="flex items-center justify-between gap-2 text-xs">
                                <span class="truncate">
                                    @if ($a['ability_id'])
                                        <a href="https://www.wowhead.com/spell={{ $a['ability_id'] }}"
                                           target="_blank" rel="noopener"
                                           class="hover:underline text-ink">{{ $a['ability_name'] }}</a>
                                    @else
                                        <span class="text-ink">{{ $a['ability_name'] }}</span>
                                    @endif
                                </span>
                                <span class="text-muted whitespace-nowrap">
                                    <span class="font-mono">{{ $a['deaths'] }}</span>
                                    @if ($a['deaths_on_wipes'] > 0 || $a['deaths_on_kills'] > 0)
                                        <span class="text-muted/70">
                                            (
                                            @if ($a['deaths_on_wipes'] > 0)
                                                <span class="text-rose-300">{{ $a['deaths_on_wipes'] }}w</span>
                                            @endif
                                            @if ($a['deaths_on_kills'] > 0)
                                                @if ($a['deaths_on_wipes'] > 0) / @endif
                                                <span class="text-emerald-300">{{ $a['deaths_on_kills'] }}k</span>
                                            @endif
                                            )
                                        </span>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    @endif
</section>
