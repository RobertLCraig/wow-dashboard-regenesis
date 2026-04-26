<section class="bg-panel border border-line rounded-lg overflow-hidden" x-data="{ search: '' }">
    <div x-data="{ explain: false }">
        <header class="px-4 py-3 border-b border-line flex items-center justify-between gap-3">
            <h2 class="text-sm font-semibold uppercase tracking-wider whitespace-nowrap flex items-center gap-2">
                <span>Alt groups</span>
                <x-explainer-toggle />
            </h2>
            <input x-model="search" type="search" placeholder="Filter by name..."
                   class="bg-bg border border-line rounded px-2 py-1 text-sm flex-1 max-w-xs">
            <span class="text-xs text-muted whitespace-nowrap">{{ $altGroups->count() }} groups</span>
        </header>
        <x-explainer-panel title="Alt groups">
            Characters that have been linked together as the same player. The "main"
            tag is taken from GRM officer notes when set, otherwise the first character
            in the group. Click a row to expand and see each alt's last-online time. Use
            it to avoid double counting one player across multiple ranks, to find a
            player's main when only an alt has logged in, and to spot mains that have
            been quiet even though their alts are active.
        </x-explainer-panel>
    </div>
    @if ($altGroups->isEmpty())
        <div class="p-8 text-center text-muted text-sm">No alt groups recorded.</div>
    @else
        <ul class="divide-y divide-line max-h-[600px] overflow-y-auto">
            @foreach ($altGroups as $group)
                @php
                    $names = $group->members->pluck('name')->implode(' ');
                    $groupId = 'alt-' . $group->id;
                @endphp
                <li x-data="{ open: false }"
                    x-show="search === '' || '{{ strtolower(addslashes($names)) }}'.includes(search.toLowerCase())"
                    class="px-4 py-2">
                    <button type="button" @click="open = !open"
                            class="w-full text-left flex items-center justify-between gap-3 text-sm">
                        <span>
                            @php
                                $main = $group->members->firstWhere('pivot.is_main', true) ?? $group->members->first();
                                $cls = 'cls-' . strtoupper($main->class ?? '');
                            @endphp
                            <span class="{{ $cls }} font-medium">{{ $main->name ?? 'unknown' }}</span>
                            <span class="text-muted text-xs ml-1">+ {{ $group->members->count() - 1 }} alts</span>
                            @if ($group->nickname)
                                <span class="text-muted text-xs ml-1 italic">"{{ $group->nickname }}"</span>
                            @endif
                        </span>
                        <span class="text-muted text-xs" x-text="open ? '−' : '+'"></span>
                    </button>
                    <ul x-show="open" x-cloak class="mt-2 ml-2 pl-3 border-l border-line space-y-1 text-xs">
                        @foreach ($group->members as $m)
                            @php $altCls = 'cls-' . strtoupper($m->class ?? ''); @endphp
                            <li class="flex items-center justify-between gap-2">
                                <span>
                                    <span class="{{ $altCls }}">{{ $m->name }}</span>
                                    @if ($m->pivot->is_main)
                                        <span class="ml-1 px-1 py-0.5 rounded bg-accent/20 text-accent text-[10px] uppercase">main</span>
                                    @endif
                                </span>
                                <span class="text-muted">{{ $m->last_online_at?->diffForHumans() ?? 'never' }}</span>
                            </li>
                        @endforeach
                    </ul>
                </li>
            @endforeach
        </ul>
    @endif
</section>
