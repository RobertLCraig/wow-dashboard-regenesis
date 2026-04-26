<section class="bg-panel border border-line rounded-lg overflow-hidden" x-data="sortableTable()">
    <div x-data="{ explain: false }">
        <header class="px-4 py-3 border-b border-line flex items-center justify-between gap-3">
            <h2 class="text-sm font-semibold uppercase tracking-wider flex items-center gap-2">
                <span>Recently inactive</span>
                <x-explainer-toggle />
            </h2>
            <div class="flex items-center gap-3">
                @if (! $inactive->isEmpty())
                    <input type="text" x-model="search" placeholder="Search name or rank..."
                           class="bg-bg border border-line rounded px-2 py-1 text-xs w-44 placeholder:text-muted">
                @endif
                <span class="text-xs text-muted">{{ count($inactive) }} shown</span>
            </div>
        </header>
        <x-explainer-panel title="Recently inactive">
            Active members who have crossed the 30-day no-login threshold but are not
            yet in the action queue. Sort by last seen to find the longest-gone, or by
            rank to spot officers and raiders that should be demoted or moved into an
            alt group. Anything past 90 days is highlighted in red. Last-online comes
            from GRM's per-character timestamp.
        </x-explainer-panel>
    </div>
    @if ($inactive->isEmpty())
        <div class="p-8 text-center text-muted text-sm">No members inactive over 30 days.</div>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs uppercase tracking-wider text-muted">
                    <th class="px-4 py-2 font-medium cursor-pointer select-none hover:text-ink" @click="sortBy('name')">
                        Name <span class="text-muted" x-text="sortIcon('name')"></span>
                    </th>
                    <th class="px-4 py-2 font-medium cursor-pointer select-none hover:text-ink" @click="sortBy('rank')">
                        Rank <span class="text-muted" x-text="sortIcon('rank')"></span>
                    </th>
                    <th class="px-4 py-2 font-medium cursor-pointer select-none hover:text-ink" @click="sortBy('lastseen')">
                        Last seen <span class="text-muted" x-text="sortIcon('lastseen')"></span>
                    </th>
                </tr>
            </thead>
            <tbody>
                @foreach ($inactive as $m)
                    @php
                        $cls = 'cls-' . strtoupper($m->class ?? '');
                        $days = $m->last_online_at?->diffInDays(now()) ?? null;
                    @endphp
                    <tr class="border-t border-line" data-row>
                        <td class="px-4 py-2" data-sort-key="name" data-sort-value="{{ strtolower($m->name) }}">
                            <span class="{{ $cls }}">{{ $m->name }}</span>
                            @if ($m->level)
                                <span class="text-muted text-xs ml-1">L{{ $m->level }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-muted" data-sort-key="rank" data-sort-value="{{ strtolower($m->rank_name ?? '') }}">{{ $m->rank_name }}</td>
                        <td class="px-4 py-2 text-muted whitespace-nowrap"
                            data-sort-key="lastseen"
                            data-sort-value="{{ $m->last_online_at?->timestamp ?? 0 }}">
                            {{ $m->last_online_at?->diffForHumans() ?? 'never' }}
                            @if ($days !== null && $days > 90)
                                <span class="text-rose-400 ml-1 text-xs">({{ floor($days) }}d)</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                <tr data-empty-message style="display:none">
                    <td colspan="3" class="px-4 py-4 text-center text-muted text-xs italic">No matches.</td>
                </tr>
            </tbody>
        </table>
    </div>
    @endif
</section>
