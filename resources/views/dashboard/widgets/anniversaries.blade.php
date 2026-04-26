<section class="bg-panel border border-line rounded-lg overflow-hidden">
    <div x-data="{ explain: false }">
        <header class="px-4 py-3 border-b border-line flex items-center justify-between">
            <h2 class="text-sm font-semibold uppercase tracking-wider flex items-center gap-2">
                <span>Anniversaries this week</span>
                <x-explainer-toggle />
            </h2>
            <span class="text-xs text-muted">{{ $anniversaries->count() }}</span>
        </header>
        <x-explainer-panel title="Anniversaries this week">
            Members hitting a guild-join anniversary (1y, 2y, 5y, etc.) within the next
            7 days, calculated from GRM's recorded join date. A free reason to ping
            someone in Discord, shout them out on raid night, or just notice that a
            long-timer is coming up on their decade. Disappears once the date passes.
        </x-explainer-panel>
    </div>
    @if ($anniversaries->isEmpty())
        <div class="p-8 text-center text-muted text-sm">No guild anniversaries this week.</div>
    @else
        <ul class="divide-y divide-line">
            @foreach ($anniversaries as $event)
                @php
                    $m = $event->member;
                    $years = (int) ($event->payload_json['years'] ?? 0);
                    $cls = 'cls-' . strtoupper($m->class ?? '');
                @endphp
                <li class="px-4 py-3 flex items-center justify-between gap-3">
                    <div>
                        <div class="text-sm">
                            <span class="{{ $cls }} font-medium">{{ $m->name }}</span>
                            <span class="text-amber-300 text-xs ml-1">{{ $years }}y</span>
                        </div>
                        <div class="text-xs text-muted">joined {{ $m->join_date?->format('d M Y') }}</div>
                    </div>
                    <span class="text-xs text-muted whitespace-nowrap">{{ $event->occurred_at->format('D d M') }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</section>
