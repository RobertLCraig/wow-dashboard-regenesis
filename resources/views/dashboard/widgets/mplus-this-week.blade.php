@php
    /**
     * Highest M+ key completed this period per character. Sourced from
     * wowaudit historical_data.dungeons_done[].level - we already
     * pre-computed the max into MemberSnapshot.mplus_keystone, so this
     * is a straight read.
     */
    $rows = collect($wowaudit['members'])
        ->filter(fn ($s) => $s->mplus_keystone !== null && $s->mplus_keystone > 0)
        ->sortByDesc('mplus_keystone')
        ->take(20)
        ->values();
@endphp
<section class="bg-panel border border-line rounded-lg overflow-hidden">
    <header class="px-4 py-3 border-b border-line flex items-center justify-between">
        <h2 class="text-sm font-semibold uppercase tracking-wider">Mythic+ this week</h2>
        <span class="text-xs text-muted">top {{ $rows->count() }}</span>
    </header>
    @if ($rows->isEmpty())
        <div class="p-8 text-center text-muted text-sm">
            No M+ data yet (or no one has run a key this week).
        </div>
    @else
        <ul class="divide-y divide-line">
            @foreach ($rows as $i => $snap)
                @php
                    $cls = 'cls-' . strtoupper($snap->member->class ?? '');
                    $level = (int) $snap->mplus_keystone;
                    $tone = $level >= 20 ? 'text-amber-300' : ($level >= 15 ? 'text-emerald-300' : 'text-muted');
                @endphp
                <li class="px-4 py-2 text-sm flex items-center justify-between gap-3">
                    <span class="flex items-center gap-3 min-w-0">
                        <span class="text-xs text-muted font-mono w-4 text-right">{{ $i + 1 }}</span>
                        <span class="{{ $cls }} truncate">{{ $snap->member->name }}</span>
                    </span>
                    <span class="font-mono {{ $tone }} text-xs">+{{ $level }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</section>
