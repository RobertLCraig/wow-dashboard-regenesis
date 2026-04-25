@php
    /**
     * Per-character vault progress: how many of the 9 Great Vault slots
     * (3 raids + 3 dungeons + 3 world) the member has unlocked this
     * period, and the highest-ilvl reward they'd be guaranteed.
     *
     * vault_progress_json shape (from wowaudit /historical_data):
     *   { raids: {option_1, option_2, option_3}, dungeons: {...}, world: {...} }
     * Each option is the ilvl of that vault slot or null if not unlocked.
     */
    $rows = collect($wowaudit['members'])->map(function ($snap) {
        $vault = $snap->vault_progress_json ?? [];
        $options = [];
        foreach (['raids', 'dungeons', 'world'] as $kind) {
            foreach (['option_1', 'option_2', 'option_3'] as $slot) {
                $v = $vault[$kind][$slot] ?? null;
                if ($v) {
                    $options[] = ['kind' => $kind, 'ilvl' => (int) $v];
                }
            }
        }
        return [
            'member' => $snap->member,
            'unlocked' => count($options),
            'best' => $options ? max(array_column($options, 'ilvl')) : null,
            'raids' => count(array_filter($options, fn ($o) => $o['kind'] === 'raids')),
            'dungeons' => count(array_filter($options, fn ($o) => $o['kind'] === 'dungeons')),
            'world' => count(array_filter($options, fn ($o) => $o['kind'] === 'world')),
        ];
    })->sortByDesc('unlocked')->values();
@endphp
<section class="bg-panel border border-line rounded-lg overflow-hidden">
    <header class="px-4 py-3 border-b border-line flex items-center justify-between">
        <h2 class="text-sm font-semibold uppercase tracking-wider">Great Vault progress (this week)</h2>
        <span class="text-xs text-muted">
            @if ($wowaudit['captured_at'])
                wowaudit {{ $wowaudit['captured_at']->diffForHumans() }}
            @else
                no data
            @endif
        </span>
    </header>
    @if ($rows->isEmpty())
        <div class="p-8 text-center text-muted text-sm">
            No wowaudit data yet. Set <code class="text-ink">WOWAUDIT_API_KEY</code> and run
            <code class="text-ink">php artisan wowaudit:pull</code>.
        </div>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs uppercase tracking-wider text-muted">
                    <th class="px-4 py-2 font-medium">Character</th>
                    <th class="px-2 py-2 font-medium">Raid</th>
                    <th class="px-2 py-2 font-medium">M+</th>
                    <th class="px-2 py-2 font-medium">World</th>
                    <th class="px-2 py-2 font-medium">Total</th>
                    <th class="px-4 py-2 font-medium text-right">Best</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    @php $cls = 'cls-' . strtoupper($row['member']->class ?? ''); @endphp
                    <tr class="border-t border-line">
                        <td class="px-4 py-2 truncate max-w-[200px]">
                            <span class="{{ $cls }}">{{ $row['member']->name }}</span>
                        </td>
                        <td class="px-2 py-2 font-mono text-muted">{{ $row['raids'] }}/3</td>
                        <td class="px-2 py-2 font-mono text-muted">{{ $row['dungeons'] }}/3</td>
                        <td class="px-2 py-2 font-mono text-muted">{{ $row['world'] }}/3</td>
                        <td class="px-2 py-2 font-mono">
                            <span class="{{ $row['unlocked'] === 9 ? 'text-emerald-300' : ($row['unlocked'] >= 6 ? 'text-amber-300' : 'text-muted') }}">
                                {{ $row['unlocked'] }}/9
                            </span>
                        </td>
                        <td class="px-4 py-2 text-right font-mono text-xs">
                            {{ $row['best'] ?? '-' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</section>
