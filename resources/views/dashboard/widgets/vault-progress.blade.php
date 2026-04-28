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

    $emptyMessage = 'No wowaudit data yet. Set <code class="text-ink">WOWAUDIT_API_KEY</code> and run <code class="text-ink">php artisan wowaudit:pull</code>.';
@endphp
<x-clarity-table
    :is-empty="$rows->isEmpty()"
    searchable
    search-placeholder="Search character..."
    :meta="$wowaudit['captured_at'] ? 'wowaudit ' . $wowaudit['captured_at']->diffForHumans() : 'no data'"
    :empty="$emptyMessage"
>
    <x-slot:header>
        <h2 class="text-sm font-semibold uppercase tracking-wider">Great Vault progress (this week)</h2>
    </x-slot:header>

    <table class="w-full text-sm clarity-tabular" x-data="{ openCol: null }">
        <thead>
            <tr class="text-left text-xs uppercase tracking-wider text-muted">
                <th class="px-4 py-2 font-medium cursor-pointer select-none hover:text-ink" @click="sortBy('character')">
                    Character <span class="text-muted" x-text="sortIcon('character')"></span>
                    <x-column-explainer-toggle col="character" />
                </th>
                <th class="px-2 py-2 font-medium cursor-pointer select-none hover:text-ink" @click="sortBy('raids')">
                    Raid <span class="text-muted" x-text="sortIcon('raids')"></span>
                    <x-column-explainer-toggle col="raids" />
                </th>
                <th class="px-2 py-2 font-medium cursor-pointer select-none hover:text-ink" @click="sortBy('dungeons')">
                    M+ <span class="text-muted" x-text="sortIcon('dungeons')"></span>
                    <x-column-explainer-toggle col="dungeons" />
                </th>
                <th class="px-2 py-2 font-medium cursor-pointer select-none hover:text-ink" @click="sortBy('world')">
                    World <span class="text-muted" x-text="sortIcon('world')"></span>
                    <x-column-explainer-toggle col="world" />
                </th>
                <th class="px-2 py-2 font-medium cursor-pointer select-none hover:text-ink" @click="sortBy('total')">
                    Total <span class="text-muted" x-text="sortIcon('total')"></span>
                    <x-column-explainer-toggle col="total" />
                </th>
                <th class="px-4 py-2 font-medium text-right cursor-pointer select-none hover:text-ink" @click="sortBy('best')">
                    Best <span class="text-muted" x-text="sortIcon('best')"></span>
                    <x-column-explainer-toggle col="best" />
                </th>
            </tr>
        </thead>
        <tbody>
            <tr x-show="openCol !== null" x-cloak class="border-t border-line bg-bg/40">
                <td colspan="6" class="px-4 py-3 text-xs text-muted leading-relaxed normal-case tracking-normal font-normal">
                    <template x-if="openCol === 'character'">
                        <div>
                            <span class="block text-ink font-semibold mb-1">Character</span>
                            Character coloured by class. All numbers in this row are sourced from
                            wowaudit's per-character snapshot for the current reset.
                        </div>
                    </template>
                    <template x-if="openCol === 'raids'">
                        <div>
                            <span class="block text-ink font-semibold mb-1">Raid</span>
                            Raid vault slots unlocked this reset, out of 3. Each slot represents a
                            tier of raid boss kills (2 / 4 / 7 bosses).
                        </div>
                    </template>
                    <template x-if="openCol === 'dungeons'">
                        <div>
                            <span class="block text-ink font-semibold mb-1">M+</span>
                            Mythic+ vault slots unlocked, out of 3. Each slot represents a tier of
                            keystone runs this week (1 / 4 / 8 dungeons).
                        </div>
                    </template>
                    <template x-if="openCol === 'world'">
                        <div>
                            <span class="block text-ink font-semibold mb-1">World</span>
                            World content vault slots unlocked, out of 3. Slot tiers come from
                            world activities (delves, world bosses, etc.).
                        </div>
                    </template>
                    <template x-if="openCol === 'total'">
                        <div>
                            <span class="block text-ink font-semibold mb-1">Total</span>
                            Combined vault slots unlocked, out of 9. Green at 9/9, amber at 6+.
                            Anything below is leaving free loot on the table; chase those before
                            weekly reset.
                        </div>
                    </template>
                    <template x-if="openCol === 'best'">
                        <div>
                            <span class="block text-ink font-semibold mb-1">Best</span>
                            Highest item level the Great Vault would currently award this character.
                            A blank cell means no slots unlocked yet this reset.
                        </div>
                    </template>
                </td>
            </tr>
            @foreach ($rows as $row)
                @php $cls = 'cls-' . strtoupper($row['member']->class ?? ''); @endphp
                <tr class="border-t border-line" data-row>
                    <td class="px-4 py-2 truncate max-w-[200px]" data-sort-key="character" data-sort-value="{{ strtolower($row['member']->name) }}">
                        <span class="inline-flex items-center gap-1.5">
                            <x-class-icon :class="$row['member']->class" />
                            <span class="{{ $cls }}">{{ $row['member']->name }}</span>
                        </span>
                    </td>
                    <td class="px-2 py-2 font-mono text-muted" data-label="Raid" data-sort-key="raids" data-sort-value="{{ $row['raids'] }}">{{ $row['raids'] }}/3</td>
                    <td class="px-2 py-2 font-mono text-muted" data-label="M+" data-sort-key="dungeons" data-sort-value="{{ $row['dungeons'] }}">{{ $row['dungeons'] }}/3</td>
                    <td class="px-2 py-2 font-mono text-muted" data-label="World" data-sort-key="world" data-sort-value="{{ $row['world'] }}">{{ $row['world'] }}/3</td>
                    <td class="px-2 py-2 font-mono" data-label="Total" data-sort-key="total" data-sort-value="{{ $row['unlocked'] }}">
                        <span class="{{ $row['unlocked'] === 9 ? 'text-emerald-300' : ($row['unlocked'] >= 6 ? 'text-amber-300' : 'text-muted') }}">
                            {{ $row['unlocked'] }}/9
                        </span>
                    </td>
                    <td class="px-4 py-2 text-right font-mono text-xs" data-label="Best ilvl" data-sort-key="best" data-sort-value="{{ $row['best'] ?? 0 }}">
                        {{ $row['best'] ?? '-' }}
                    </td>
                </tr>
            @endforeach
            <tr data-empty-message style="display:none">
                <td colspan="6" class="px-4 py-4 text-center text-muted text-xs italic">No characters match.</td>
            </tr>
        </tbody>
    </table>
</x-clarity-table>
