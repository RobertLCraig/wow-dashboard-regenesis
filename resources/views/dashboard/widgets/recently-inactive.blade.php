<section class="bg-panel border border-line rounded-lg overflow-hidden">
    <header class="px-4 py-3 border-b border-line flex items-center justify-between">
        <h2 class="text-sm font-semibold uppercase tracking-wider">Recently inactive</h2>
        <span class="text-xs text-muted">{{ count($inactive) }} shown</span>
    </header>
    @if ($inactive->isEmpty())
        <div class="p-8 text-center text-muted text-sm">No members inactive over 30 days.</div>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs uppercase tracking-wider text-muted">
                    <th class="px-4 py-2 font-medium">Name</th>
                    <th class="px-4 py-2 font-medium">Rank</th>
                    <th class="px-4 py-2 font-medium">Last seen</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($inactive as $m)
                    @php
                        $cls = 'cls-' . strtoupper($m->class ?? '');
                        $days = $m->last_online_at?->diffInDays(now()) ?? null;
                    @endphp
                    <tr class="border-t border-line">
                        <td class="px-4 py-2">
                            <span class="{{ $cls }}">{{ $m->name }}</span>
                            @if ($m->level)
                                <span class="text-muted text-xs ml-1">L{{ $m->level }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-muted">{{ $m->rank_name }}</td>
                        <td class="px-4 py-2 text-muted whitespace-nowrap">
                            {{ $m->last_online_at?->diffForHumans() ?? 'never' }}
                            @if ($days !== null && $days > 90)
                                <span class="text-rose-400 ml-1 text-xs">({{ floor($days) }}d)</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</section>
