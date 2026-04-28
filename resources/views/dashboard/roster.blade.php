@extends('layouts.dashboard')

@section('title', 'Roster')

@section('content')
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-xl font-semibold">Roster</h1>
        <a href="{{ route('roster.csv', ['filter' => $filter]) }}"
           class="text-sm px-3 py-1.5 rounded border border-line bg-bg hover:bg-panel">
            Export CSV
        </a>
    </div>

    @php
        /**
         * Filter chips. Each is a link that swaps `?filter=`. Active chip
         * gets the accent border; counts come from the controller so the
         * officer sees what each chip will reveal before clicking.
         */
        $chips = [
            ['key' => 'all',           'label' => 'All'],
            ['key' => 'inactive_7d',   'label' => 'Inactive 7d'],
            ['key' => 'inactive_14d',  'label' => 'Inactive 14d'],
            ['key' => 'inactive_30d',  'label' => 'Inactive 30d'],
            ['key' => 'inactive_60d',  'label' => 'Inactive 60d'],
            ['key' => 'inactive_90d',  'label' => 'Inactive 90d'],
            ['key' => 'mains',         'label' => 'Mains'],
            ['key' => 'alts',          'label' => 'Alts'],
            ['key' => 'trial',         'label' => 'Trial'],
            ['key' => 'action_queue',  'label' => 'Action queue'],
            ['key' => 'bis_issues',    'label' => 'BiS issues'],
            ['key' => 'banned',        'label' => 'Banned'],
            ['key' => 'no_keys_14d',   'label' => 'No keys 14d'],
            ['key' => 'no_keys_30d',   'label' => 'No keys 30d'],
        ];
    @endphp

    <div class="flex flex-wrap items-center gap-2 mb-4">
        @foreach ($chips as $chip)
            @php
                $active = $filter === $chip['key'];
                $count = $counts[$chip['key']] ?? 0;
                $chipQuery = ['filter' => $chip['key']];
                if ($grouped) {
                    $chipQuery['group'] = 1;
                }
            @endphp
            <a href="{{ route('roster.index', $chipQuery) }}"
               class="text-xs px-2 py-1 rounded border transition
                      {{ $active
                          ? 'border-accent bg-accent/15 text-ink'
                          : 'border-line bg-bg text-muted hover:text-ink hover:border-muted' }}">
                {{ $chip['label'] }}
                <span class="ml-1 text-[10px] {{ $active ? 'text-ink/80' : 'text-muted/70' }}">{{ $count }}</span>
            </a>
        @endforeach

        {{-- Grouping toggle. Mirror of the filter chips: an anchor that
             flips ?group= on/off. Default is flat (off) so search +
             column sort behave the obvious way; grouped mode collapses
             alts under their main with an expand caret. --}}
        <a href="{{ route('roster.index', $grouped ? ['filter' => $filter] : ['filter' => $filter, 'group' => 1]) }}"
           class="text-xs px-2 py-1 rounded border transition ml-auto
                  {{ $grouped
                      ? 'border-accent bg-accent/15 text-ink'
                      : 'border-line bg-bg text-muted hover:text-ink hover:border-muted' }}"
           title="{{ $grouped ? 'Showing one row per alt group; click for flat list' : 'Showing every character as its own row; click to group alts under mains' }}">
            {{ $grouped ? 'Grouped' : 'Group alts' }}
        </a>
    </div>

    <x-clarity-table
        :is-empty="$rows->isEmpty()"
        searchable
        search-placeholder="Search name, class, rank, team..."
        empty="No members match this filter."
    >
        <x-slot:header>
            <h2 class="text-sm font-semibold uppercase tracking-wider">
                {{ $rows->count() }} {{ \Illuminate\Support\Str::plural('member', $rows->count()) }}
            </h2>
        </x-slot:header>

        <table class="w-full text-sm clarity-tabular" x-data="{ openCol: null }">
            <thead>
                <tr class="text-left text-xs uppercase tracking-wider text-muted">
                    <th class="px-4 py-2 font-medium cursor-pointer select-none hover:text-ink" @click="sortBy('name')">
                        Name <span class="text-muted" x-text="sortIcon('name')"></span>
                        <x-column-explainer-toggle col="name" />
                    </th>
                    <th class="px-2 py-2 font-medium cursor-pointer select-none hover:text-ink" @click="sortBy('rank')">
                        Rank <span class="text-muted" x-text="sortIcon('rank')"></span>
                        <x-column-explainer-toggle col="rank" />
                    </th>
                    <th class="px-2 py-2 font-medium cursor-pointer select-none hover:text-ink text-right" @click="sortBy('ilvl')">
                        ilvl <span class="text-muted" x-text="sortIcon('ilvl')"></span>
                        <x-column-explainer-toggle col="ilvl" />
                    </th>
                    <th class="px-2 py-2 font-medium cursor-pointer select-none hover:text-ink text-right" @click="sortBy('rio')">
                        RIO <span class="text-muted" x-text="sortIcon('rio')"></span>
                        <x-column-explainer-toggle col="rio" />
                    </th>
                    <th class="px-2 py-2 font-medium cursor-pointer select-none hover:text-ink text-right" @click="sortBy('keys30d')">
                        Keys 30d <span class="text-muted" x-text="sortIcon('keys30d')"></span>
                        <x-column-explainer-toggle col="keys30d" />
                    </th>
                    <th class="px-2 py-2 font-medium cursor-pointer select-none hover:text-ink text-center" @click="sortBy('bis')">
                        BiS <span class="text-muted" x-text="sortIcon('bis')"></span>
                        <x-column-explainer-toggle col="bis" />
                    </th>
                    <th class="px-2 py-2 font-medium cursor-pointer select-none hover:text-ink text-center" @click="sortBy('gear')">
                        Gear <span class="text-muted" x-text="sortIcon('gear')"></span>
                        <x-column-explainer-toggle col="gear" />
                    </th>
                    <th class="px-2 py-2 font-medium cursor-pointer select-none hover:text-ink" @click="sortBy('lastseen')">
                        Last seen <span class="text-muted" x-text="sortIcon('lastseen')"></span>
                        <x-column-explainer-toggle col="lastseen" />
                    </th>
                    <th class="px-2 py-2 font-medium">
                        Alt of
                        <x-column-explainer-toggle col="altof" />
                    </th>
                    <th class="px-2 py-2 font-medium">
                        Flags
                        <x-column-explainer-toggle col="flags" />
                    </th>
                    <th class="px-2 py-2 font-medium text-right">
                        Links
                        <x-column-explainer-toggle col="links" />
                    </th>
                    @can('roster.kick')
                        <th class="px-4 py-2 font-medium text-right">
                            Actions
                            <x-column-explainer-toggle col="actions" />
                        </th>
                    @endcan
                </tr>
            </thead>
            <tbody>
                @php $colspan = auth()->user()?->can('roster.kick') ? 12 : 11; @endphp
                <tr x-show="openCol !== null" x-cloak class="border-t border-line bg-bg/40">
                    <td colspan="{{ $colspan }}"
                        class="px-4 py-3 text-xs text-muted leading-relaxed normal-case tracking-normal font-normal">
                        <template x-if="openCol === 'name'">
                            <div>
                                <span class="block text-ink font-semibold mb-1">Name</span>
                                Character name, coloured by class, with display class + level inline so
                                near-identical diacritic names (Ñýxx Shaman 90 vs Ñyxx Rogue 80) read
                                clearly at a glance. Click the name to open the character page. In
                                Group alts mode, mains with linked alts show an expand caret and a
                                "+ N alts" marker; expanding lists each alt with its own last-seen.
                            </div>
                        </template>
                        <template x-if="openCol === 'rank'">
                            <div>
                                <span class="block text-ink font-semibold mb-1">Rank</span>
                                In-game guild rank from GRM. Sort order matches the in-game ranking,
                                so Guild Master sorts first and the lowest rank sorts last.
                            </div>
                        </template>
                        <template x-if="openCol === 'ilvl'">
                            <div>
                                <span class="block text-ink font-semibold mb-1">ilvl</span>
                                Equipped item level. Falls back through raider.io, GRM and the WCL
                                fight roster, whichever number is freshest. Hover the value to see
                                which source it came from.
                            </div>
                        </template>
                        <template x-if="openCol === 'rio'">
                            <div>
                                <span class="block text-ink font-semibold mb-1">RIO</span>
                                Highest mythic+ rating from raider.io across all dungeons in the
                                current season. Updates whenever a raider.io scrape runs.
                            </div>
                        </template>
                        <template x-if="openCol === 'keys30d'">
                            <div>
                                <span class="block text-ink font-semibold mb-1">Keys 30d</span>
                                Number of mythic+ keys completed in the last 30 days, with the
                                highest level seen in brackets. Sourced from raider.io and
                                deduped across pulls. Empty cell = nothing in 30 days, which is
                                also the No keys 30d filter chip. Hover to see the most recent
                                completion timestamp.
                            </div>
                        </template>
                        <template x-if="openCol === 'bis'">
                            <div>
                                <span class="block text-ink font-semibold mb-1">BiS</span>
                                Best-in-slot issues against the SimulationCraft profile for this
                                character's class and spec: missing or wrong enchants, plus missing
                                or wrong gem slots. OK is none, amber is 1 to 3, red is 4 or more.
                                Hover the number for the breakdown.
                            </div>
                        </template>
                        <template x-if="openCol === 'gear'">
                            <div>
                                <span class="block text-ink font-semibold mb-1">Gear</span>
                                Universal gear-readiness check from the latest Blizzard equipment
                                pull. Counts slots that should be enchanted but aren't, plus any
                                empty sockets. No SimC profile needed, so this works for trials,
                                fresh alts and classes the BiS column has no profile for.
                                OK is none, amber is 1 to 3, red is 4 or more. Hover the number
                                for the slot breakdown.
                            </div>
                        </template>
                        <template x-if="openCol === 'lastseen'">
                            <div>
                                <span class="block text-ink font-semibold mb-1">Last seen</span>
                                Last in-game login from GRM's per-character timestamp. Anything past
                                90 days is highlighted in red and lines up with the inactive_90d chip.
                                "never" means GRM has the character but no login on record yet.
                            </div>
                        </template>
                        <template x-if="openCol === 'altof'">
                            <div>
                                <span class="block text-ink font-semibold mb-1">Alt of</span>
                                Main this character is linked under, taken from GRM officer notes.
                                Manage groupings in the Alt groups widget on the General page.
                            </div>
                        </template>
                        <template x-if="openCol === 'flags'">
                            <div>
                                <span class="block text-ink font-semibold mb-1">Flags</span>
                                Suggestions raised by the ranking rules: promote, demote, kick,
                                banned. Same source as the Action queue chip; an empty cell means
                                no rule has fired on this character. "main?" appears on alt-group
                                heads where at least one alt has been online 14+ days more
                                recently than the main, a heads-up that the main designation in
                                GRM may have drifted.
                            </div>
                        </template>
                        <template x-if="openCol === 'links'">
                            <div>
                                <span class="block text-ink font-semibold mb-1">Links</span>
                                External profile links: Warcraft Logs, raider.io and the in-game
                                armoury. Each opens in a new tab.
                            </div>
                        </template>
                        <template x-if="openCol === 'actions'">
                            <div>
                                <span class="block text-ink font-semibold mb-1">Actions</span>
                                Builds a /gremove macro for this character plus any linked alts.
                                It does not kick anyone by itself; paste the macro into in-game
                                chat to actually remove them.
                            </div>
                        </template>
                    </td>
                </tr>
                @foreach ($rows as $row)
                    @php
                        $m = $row['member'];
                        $snap = $row['snap'];
                        $cls = 'cls-' . strtoupper($m->class ?? '');
                    @endphp
                    <tr class="border-t border-line" data-row>
                        <td class="px-4 py-2 align-top" data-sort-key="name" data-sort-value="{{ strtolower($m->name) }}"
                            @if ($grouped && $row['alts']->isNotEmpty()) x-data="{ open: false }" @endif>
                            <span class="inline-flex items-center gap-1.5">
                                @if ($grouped && $row['alts']->isNotEmpty())
                                    {{-- Expand caret. Flat mode never shows this; grouped mode shows it
                                         only on rows that actually have alts to reveal. --}}
                                    <button type="button"
                                            @click="open = !open"
                                            class="w-4 text-muted hover:text-ink text-xs leading-none select-none"
                                            :aria-expanded="open"
                                            aria-label="Show alts">
                                        <span x-text="open ? '▾' : '▸'"></span>
                                    </button>
                                @endif
                                <x-class-icon :class="$m->class" />
                                <a href="{{ route('character.show', $m->name) }}" class="{{ $cls }} hover:underline">
                                    <x-character-name :name="$m->name" :siblings="$row['siblings']" />
                                </a>
                                @if ($grouped && $row['alts']->isNotEmpty())
                                    <span class="text-muted text-xs ml-1">+ {{ $row['alts']->count() }} {{ \Illuminate\Support\Str::plural('alt', $row['alts']->count()) }}</span>
                                @endif
                            </span>
                            @if ($m->class_display)
                                <span class="{{ $cls }} text-xs ml-1 opacity-75">{{ $m->class_display }}</span>
                            @endif
                            @if ($m->level)
                                <span class="text-muted text-xs ml-1">L{{ $m->level }}</span>
                            @endif

                            @if ($grouped && $row['alts']->isNotEmpty())
                                <ul x-show="open" x-cloak class="mt-2 ml-6 pl-3 border-l border-line space-y-1 text-xs">
                                    @foreach ($row['alts'] as $alt)
                                        @php
                                            $altCls = 'cls-' . strtoupper($alt->class ?? '');
                                            $altSiblings = $row['alts']->where('id', '!=', $alt->id)->pluck('name')->push($m->name)->all();
                                        @endphp
                                        <li class="flex items-center justify-between gap-2">
                                            <span class="inline-flex items-center gap-1.5">
                                                <x-class-icon :class="$alt->class" :size="14" />
                                                <a href="{{ route('character.show', $alt->name) }}" class="{{ $altCls }} hover:underline">
                                                    <x-character-name :name="$alt->name" :siblings="$altSiblings" />
                                                </a>
                                                @if ($alt->class_display)
                                                    <span class="{{ $altCls }} opacity-75">{{ $alt->class_display }}</span>
                                                @endif
                                                @if ($alt->level)
                                                    <span class="text-muted">L{{ $alt->level }}</span>
                                                @endif
                                            </span>
                                            <span class="text-muted">{{ $alt->last_online_at?->diffForHumans() ?? 'never' }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </td>
                        <td class="px-2 py-2 text-muted" data-label="Rank" data-sort-key="rank" data-sort-value="{{ $m->rank_index ?? 99 }}">
                            {{ $m->rank_name }}
                        </td>
                        <td class="px-2 py-2 font-mono text-right" data-label="ilvl" data-sort-key="ilvl" data-sort-value="{{ $row['ilvl'] ?? 0 }}">
                            @if ($row['ilvl'] !== null)
                                <span title="via {{ $row['ilvl_source'] }}">{{ $row['ilvl'] }}</span>
                            @else
                                -
                            @endif
                        </td>
                        <td class="px-2 py-2 font-mono text-right" data-label="RIO" data-sort-key="rio" data-sort-value="{{ $snap?->mplus_score ?? 0 }}">
                            {{ $snap?->mplus_score !== null ? number_format($snap->mplus_score, 0) : '-' }}
                        </td>
                        @php $act = $row['mplus_activity'] ?? null; @endphp
                        <td class="px-2 py-2 font-mono text-right text-xs"
                            data-label="Keys 30d"
                            data-sort-key="keys30d"
                            data-sort-value="{{ $act['count'] ?? 0 }}">
                            @if ($act === null)
                                <span class="text-muted">-</span>
                            @else
                                <span class="text-ink"
                                      title="last: {{ $act['last_completed_at']->diffForHumans() }} ({{ $act['last_completed_at']->format('Y-m-d H:i') }})">
                                    {{ $act['count'] }}
                                    <span class="text-muted">(+{{ $act['highest'] }})</span>
                                </span>
                            @endif
                        </td>
                        @php $bis = $row['bis_issues']; @endphp
                        <td class="px-2 py-2 font-mono text-center text-xs"
                            data-label="BiS"
                            data-sort-key="bis"
                            data-sort-value="{{ $bis['total'] ?? -1 }}">
                            @if ($bis === null)
                                <span class="text-muted">-</span>
                            @elseif ($bis['total'] === 0)
                                <span class="text-emerald-400">OK</span>
                            @else
                                <span class="{{ $bis['total'] >= 4 ? 'text-red-400' : 'text-amber-400' }}"
                                      title="{{ $bis['missing_enchants'] }} missing enchants, {{ $bis['wrong_enchants'] }} wrong; {{ $bis['missing_gems'] }} missing gem slots, {{ $bis['wrong_gems'] }} wrong">
                                    {{ $bis['total'] }}
                                </span>
                            @endif
                        </td>
                        @php $gh = $row['gear_health']; @endphp
                        <td class="px-2 py-2 font-mono text-center text-xs"
                            data-label="Gear"
                            data-sort-key="gear"
                            data-sort-value="{{ $gh['total_issues'] ?? -1 }}">
                            @if ($gh === null)
                                <span class="text-muted">-</span>
                            @elseif ($gh['total_issues'] === 0)
                                <span class="text-emerald-400">OK</span>
                            @else
                                @php
                                    $missingSlots = implode(', ', $gh['missing_enchants']) ?: 'none';
                                    $emptySlots = implode(', ', $gh['empty_sockets']) ?: 'none';
                                    $title = "Missing enchants: {$missingSlots}. Empty sockets: {$emptySlots}.";
                                @endphp
                                <span class="{{ $gh['total_issues'] >= 4 ? 'text-red-400' : 'text-amber-400' }}"
                                      title="{{ $title }}">
                                    {{ $gh['total_issues'] }}
                                </span>
                            @endif
                        </td>
                        <td class="px-2 py-2 text-muted whitespace-nowrap"
                            data-label="Last seen"
                            data-sort-key="lastseen"
                            data-sort-value="{{ $m->last_online_at?->timestamp ?? 0 }}">
                            {{ $m->last_online_at?->diffForHumans() ?? 'never' }}
                        </td>
                        <td class="px-2 py-2 text-muted text-xs" data-label="Alt of">
                            @if ($row['main'])
                                {{ $row['main']->name }}
                            @endif
                        </td>
                        <td class="px-2 py-2" data-label="Flags">
                            @foreach ($row['flags'] as $flag)
                                @php
                                    $tone = match ($flag) {
                                        'promote' => 'border-emerald-700/50 text-emerald-300',
                                        'demote'  => 'border-amber-700/50 text-amber-300',
                                        'kick','banned' => 'border-rose-700/50 text-rose-300',
                                        'main?' => 'border-amber-700/50 text-amber-300',
                                        default => 'border-line text-muted',
                                    };
                                    $title = match ($flag) {
                                        'main?' => 'An alt has logged in 14+ days more recently than this character. The main designation in GRM may be stale.',
                                        default => null,
                                    };
                                @endphp
                                <span class="inline-block text-[10px] uppercase tracking-wider border rounded px-1 py-0.5 mr-1 {{ $tone }}"
                                      @if ($title) title="{{ $title }}" @endif>
                                    {{ $flag }}
                                </span>
                            @endforeach
                        </td>
                        <td class="px-2 py-2 text-right" data-label="Links">
                            <x-character-links :member="$m" />
                        </td>
                        @can('roster.kick')
                            <td class="px-4 py-2 text-right whitespace-nowrap" data-label="Actions">
                                {{-- Note edit is the lowest-stakes action so it sits first
                                     in the cell. Always available; Note has no membership
                                     prereq (anyone in the guild can have one). --}}
                                <button type="button"
                                        @click="$dispatch('open-custom-note', { id: {{ $m->id }}, name: @js($m->name), class: @js($m->class ?? '') })"
                                        class="text-[10px] uppercase tracking-wider px-2 py-0.5 mr-1 rounded border border-sky-700/50 text-sky-300 hover:bg-sky-950/30"
                                        title="Edit GRM custom note for {{ $m->name }}">
                                    Note
                                </button>
                                {{-- Promote/Demote only show on rows where the recommendation
                                     fired, to keep the cell tidy on the long tail of "no
                                     action needed" rows. Officers can still see them via the
                                     Action queue chip. --}}
                                @if ($m->recommend_promote)
                                    <button type="button"
                                            @click="$dispatch('open-rank-macro', { op: 'promote', ids: [{{ $m->id }}] })"
                                            class="text-[10px] uppercase tracking-wider px-2 py-0.5 mr-1 rounded border border-emerald-700/50 text-emerald-300 hover:bg-emerald-950/30"
                                            title="Generate /gpromote macro for {{ $m->name }}">
                                        Promote
                                    </button>
                                @endif
                                @if ($m->recommend_demote)
                                    <button type="button"
                                            @click="$dispatch('open-rank-macro', { op: 'demote', ids: [{{ $m->id }}] })"
                                            class="text-[10px] uppercase tracking-wider px-2 py-0.5 mr-1 rounded border border-amber-700/50 text-amber-300 hover:bg-amber-950/30"
                                            title="Generate /gdemote macro for {{ $m->name }}">
                                        Demote
                                    </button>
                                @endif
                                {{-- Set Main and Unlink only make sense for characters that
                                     are in an alt group. Hidden elsewhere to keep the cell tidy. --}}
                                @if ($m->alt_group_id !== null)
                                    <button type="button"
                                            @click="$dispatch('open-set-main', { ids: [{{ $m->id }}] })"
                                            class="text-[10px] uppercase tracking-wider px-2 py-0.5 mr-1 rounded border border-amber-700/50 text-amber-300 hover:bg-amber-950/30"
                                            title="Generate /run GRM.SetMain macro for {{ $m->name }}">
                                        Main
                                    </button>
                                    <button type="button"
                                            @click="$dispatch('open-unlink-alt', { ids: [{{ $m->id }}] })"
                                            class="text-[10px] uppercase tracking-wider px-2 py-0.5 mr-1 rounded border border-violet-700/50 text-violet-300 hover:bg-violet-950/30"
                                            title="Generate /run GRM.RemovePlayerFromAltGroup macro for {{ $m->name }}">
                                        Unlink
                                    </button>
                                @endif
                                <button type="button"
                                        @click="$dispatch('open-kick-macro', { ids: {{ json_encode($row['group_member_ids']) }} })"
                                        class="text-[10px] uppercase tracking-wider px-2 py-0.5 rounded border border-rose-700/50 text-rose-300 hover:bg-rose-950/30"
                                        title="Generate /gremove macro for {{ $m->name }}{{ count($row['group_member_ids']) > 1 ? ' + ' . (count($row['group_member_ids']) - 1) . ' alts' : '' }}">
                                    Kick{{ count($row['group_member_ids']) > 1 ? ' +' . (count($row['group_member_ids']) - 1) : '' }}
                                </button>
                            </td>
                        @endcan
                    </tr>
                @endforeach
                <tr data-empty-message style="display:none">
                    <td colspan="{{ $colspan }}" class="px-4 py-4 text-center text-muted text-xs italic">No matches.</td>
                </tr>
            </tbody>
        </table>
    </x-clarity-table>

    @can('roster.kick')
        <x-kick-macro-modal />
        <x-set-main-macro-modal />
        <x-rank-macro-modal />
        <x-custom-note-macro-modal />
        <x-unlink-alt-macro-modal />
    @endcan
@endsection
