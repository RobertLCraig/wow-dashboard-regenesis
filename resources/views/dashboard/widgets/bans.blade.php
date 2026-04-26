<section class="bg-panel border border-line rounded-lg overflow-hidden">
    <div x-data="{ explain: false }">
        <header class="px-4 py-3 border-b border-line flex items-center justify-between">
            <h2 class="text-sm font-semibold uppercase tracking-wider flex items-center gap-2">
                <span>Ban list</span>
                <x-explainer-toggle />
            </h2>
            <span class="text-xs text-muted">{{ $bans->count() }}</span>
        </header>
        <x-explainer-panel title="Ban list">
            Names recorded as banned, either flagged in the GRM addon or via the
            dashboard. Reason and date shown when set. Use as a quick reference before
            re-inviting someone you don't recognise, or before vouching for a returning
            player. Bans without a reason should be back-filled when you spot them.
        </x-explainer-panel>
    </div>
    @if ($bans->isEmpty())
        <div class="p-8 text-center text-muted text-sm">No bans on record.</div>
    @else
        <ul class="divide-y divide-line">
            @foreach ($bans as $b)
                @php $cls = 'cls-' . strtoupper($b->class ?? ''); @endphp
                <li class="px-4 py-3">
                    <div class="text-sm">
                        <span class="{{ $cls }} font-medium">{{ $b->name }}</span>
                        <span class="text-muted text-xs ml-1">L{{ $b->level }} {{ $b->rank_name }}</span>
                    </div>
                    <div class="text-xs text-muted mt-1">
                        @if ($b->reason_banned)
                            <span class="text-rose-300">{{ $b->reason_banned }}</span>
                        @else
                            <span class="italic">no reason recorded</span>
                        @endif
                        @if ($b->banned_at)
                            <span class="ml-2">{{ $b->banned_at->diffForHumans() }}</span>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>
