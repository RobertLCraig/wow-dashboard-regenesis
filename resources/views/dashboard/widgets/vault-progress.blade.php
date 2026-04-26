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
<section class="bg-panel border border-line rounded-lg overflow-hidden" x-data="sortableTable()">
    <div x-data="{ explain: false }">
        <header class="px-4 py-3 border-b border-line flex items-center justify-between gap-3">
            <h2 class="text-sm font-semibold uppercase tracking-wider flex items-center gap-2">
                <span>Great Vault progress (this week)</span>
                <x-explainer-toggle />
            </h2>
            <div class="flex items-center gap-3">
                @if (! $rows->isEmpty())
                    <input type="text" x-model="search" placeholder="Search character..."
                           class="bg-bg border border-line rounded px-2 py-1 text-xs w-40 placeholder:text-muted">
                @endif
                <span class="text-xs text-muted">
                    @if ($wowaudit['captured_at'])
                        wowaudit {{ $wowaudit['captured_at']->diffForHumans() }}
                    @else
                        no data
                    @endif
                </span>
            </div>
        </header>
        <x-explainer-panel title="Great Vault progress">
            For each character, how many of the 9 Great Vault slots have been unlocked
            this reset (3 from raid bosses, 3 from M+ keys, 3 from world activities) and
            the highest item level the vault would currently award. Sourced from
            wowaudit's per-character snapshot. Use it to chase up raiders who haven't
            capped their vault before reset (free loot left on the table) and to spot
            anyone who's clearly not engaging with their character that week. 9/9 turns
            green, 6+ turns amber.
        </x-explainer-panel>
    </div>
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
                    <th class="px-4 py-2 font-medium cursor-pointer select-none hover:text-ink" @click="sortBy('character')">
                        Character <span class="text-muted" x-text="sortIcon('character')"></span>
                    </th>
                    <th class="px-2 py-2 font-medium cursor-pointer select-none hover:text-ink" @click="sortBy('raids')">
                        Raid <span class="text-muted" x-text="sortIcon('raids')"></span>
                    </th>
                    <th class="px-2 py-2 font-medium cursor-pointer select-none hover:text-ink" @click="sortBy('dungeons')">
                        M+ <span class="text-muted" x-text="sortIcon('dungeons')"></span>
                    </th>
                    <th class="px-2 py-2 font-medium cursor-pointer select-none hover:text-ink" @click="sortBy('world')">
                        World <span class="text-muted" x-text="sortIcon('world')"></span>
                    </th>
                    <th class="px-2 py-2 font-medium cursor-pointer select-none hover:text-ink" @click="sortBy('total')">
                        Total <span class="text-muted" x-text="sortIcon('total')"></span>
                    </th>
                    <th class="px-4 py-2 font-medium text-right cursor-pointer select-none hover:text-ink" @click="sortBy('best')">
                        Best <span class="text-muted" x-text="sortIcon('best')"></span>
                    </th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    @php $cls = 'cls-' . strtoupper($row['member']->class ?? ''); @endphp
                    <tr class="border-t border-line" data-row>
                        <td class="px-4 py-2 truncate max-w-[200px]" data-sort-key="character" data-sort-value="{{ strtolower($row['member']->name) }}">
                            <span class="{{ $cls }}">{{ $row['member']->name }}</span>
                        </td>
                        <td class="px-2 py-2 font-mono text-muted" data-sort-key="raids" data-sort-value="{{ $row['raids'] }}">{{ $row['raids'] }}/3</td>
                        <td class="px-2 py-2 font-mono text-muted" data-sort-key="dungeons" data-sort-value="{{ $row['dungeons'] }}">{{ $row['dungeons'] }}/3</td>
                        <td class="px-2 py-2 font-mono text-muted" data-sort-key="world" data-sort-value="{{ $row['world'] }}">{{ $row['world'] }}/3</td>
                        <td class="px-2 py-2 font-mono" data-sort-key="total" data-sort-value="{{ $row['unlocked'] }}">
                            <span class="{{ $row['unlocked'] === 9 ? 'text-emerald-300' : ($row['unlocked'] >= 6 ? 'text-amber-300' : 'text-muted') }}">
                                {{ $row['unlocked'] }}/9
                            </span>
                        </td>
                        <td class="px-4 py-2 text-right font-mono text-xs" data-sort-key="best" data-sort-value="{{ $row['best'] ?? 0 }}">
                            {{ $row['best'] ?? '-' }}
                        </td>
                    </tr>
                @endforeach
                <tr data-empty-message style="display:none">
                    <td colspan="6" class="px-4 py-4 text-center text-muted text-xs italic">No characters match.</td>
                </tr>
            </tbody>
        </table>
    </div>
    @endif
</section>
