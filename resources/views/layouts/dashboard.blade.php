<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Dashboard') | Regenesis</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Tailwind via CDN keeps the deploy story simple while widgets
         are still being added. We'll move to a proper Vite build once
         the structure stabilises (probably when we add the event
         creator form). --}}
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>[x-cloak] { display: none !important; }</style>
    {{-- Reusable Alpine factory for sortable + searchable tables.
         Wrap a table region with x-data="sortableTable()", mark data rows with
         data-row, give each sortable cell data-sort-key="X" and (optionally)
         data-sort-value="..." for a non-text sort key. Headers call sortBy('X')
         and render an indicator with sortIcon('X'). Bind a no-name search
         input via x-model="search". Trailing rows that should never sort or
         hide (e.g. "add new" rows) get data-table-trailing. --}}
    <script>
        function sortableTable() {
            return {
                search: '',
                sortKey: null,
                sortDir: 'asc',
                init() {
                    this.$watch('search', () => this.applyFilter());
                },
                rowText(row) {
                    const parts = [row.textContent];
                    row.querySelectorAll('input, select, textarea').forEach(el => {
                        if (el.type === 'hidden' || el.type === 'checkbox' || el.type === 'submit') return;
                        if (el.tagName === 'SELECT') {
                            parts.push(el.options[el.selectedIndex]?.text || '');
                        } else {
                            parts.push(el.value);
                        }
                    });
                    return parts.join(' ').toLowerCase();
                },
                applyFilter() {
                    const q = this.search.trim().toLowerCase();
                    const rows = this.$root.querySelectorAll('tbody tr[data-row]');
                    let visible = 0;
                    rows.forEach(r => {
                        const match = q === '' || this.rowText(r).includes(q);
                        r.style.display = match ? '' : 'none';
                        if (match) visible++;
                    });
                    const empty = this.$root.querySelector('[data-empty-message]');
                    if (empty) empty.style.display = (visible === 0 && q !== '' && rows.length > 0) ? '' : 'none';
                },
                coerce(raw) {
                    const t = (raw ?? '').toString().trim();
                    if (t === '') return '';
                    const n = Number(t);
                    return Number.isNaN(n) ? t.toLowerCase() : n;
                },
                cellValue(row, key) {
                    const cell = row.querySelector(`[data-sort-key="${key}"]`);
                    if (!cell) return '';
                    if (cell.dataset.sortValue !== undefined) return this.coerce(cell.dataset.sortValue);
                    const inp = cell.querySelector('input:not([type=hidden]):not([type=checkbox]), select, textarea');
                    if (inp) return this.coerce(inp.value);
                    return this.coerce(cell.textContent);
                },
                sortBy(key) {
                    if (this.sortKey === key) {
                        this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
                    } else {
                        this.sortKey = key;
                        this.sortDir = 'asc';
                    }
                    const tbody = this.$root.querySelector('tbody');
                    if (!tbody) return;
                    const all = Array.from(tbody.children);
                    const dataRows = all.filter(r => r.matches('tr[data-row]'));
                    const trailing = all.filter(r => r.matches('tr[data-table-trailing], tr[data-empty-message]'));
                    const others = all.filter(r => !r.matches('tr[data-row], tr[data-table-trailing], tr[data-empty-message]'));
                    const dir = this.sortDir === 'asc' ? 1 : -1;
                    dataRows.sort((a, b) => {
                        const av = this.cellValue(a, key);
                        const bv = this.cellValue(b, key);
                        if (av === '' && bv !== '') return 1;
                        if (bv === '' && av !== '') return -1;
                        if (av < bv) return -1 * dir;
                        if (av > bv) return 1 * dir;
                        return 0;
                    });
                    tbody.replaceChildren(...others, ...dataRows, ...trailing);
                },
                sortIcon(key) {
                    if (this.sortKey !== key) return '↕';
                    return this.sortDir === 'asc' ? '▲' : '▼';
                },
            };
        }
    </script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        bg: '#0b0b14',
                        panel: '#15151f',
                        line: '#252533',
                        ink: '#e6e6f0',
                        muted: '#7a7a8c',
                        accent: '#5865F2',
                    },
                },
            },
        };
    </script>
    {{-- WoW class colours, used by the timeline + inactive list. --}}
    <style>
        .cls-DEATHKNIGHT { color: #C41E3A; }
        .cls-DEMONHUNTER { color: #A330C9; }
        .cls-DRUID       { color: #FF7C0A; }
        .cls-EVOKER      { color: #33937F; }
        .cls-HUNTER      { color: #AAD372; }
        .cls-MAGE        { color: #3FC7EB; }
        .cls-MONK        { color: #00FF98; }
        .cls-PALADIN     { color: #F48CBA; }
        .cls-PRIEST      { color: #FFFFFF; }
        .cls-ROGUE       { color: #FFF468; }
        .cls-SHAMAN      { color: #0070DD; }
        .cls-WARLOCK     { color: #8788EE; }
        .cls-WARRIOR     { color: #C69B6D; }
    </style>
    @stack('head')
</head>
<body class="bg-bg text-ink font-sans antialiased min-h-screen" x-data="{ navOpen: false }">
@php
    /**
     * Sidebar nav model. Primary items are the four section pages
     * (General + the three team pages). Admin items are utility pages
     * an officer touches occasionally (event list, mapping, sync).
     * Each item carries the route name(s) it should match for the
     * "active" highlight + the gate ability that gates the link.
     */
    $navPrimary = [
        ['route' => 'dashboard',              'label' => 'General',          'matches' => ['dashboard'],              'can' => 'dashboard.general.view'],
        ['route' => 'roster.index',           'label' => 'Roster',           'matches' => ['roster.*'],               'can' => 'roster.view'],
        ['route' => 'dashboard.team.heroic',  'label' => 'Heroic Team',      'matches' => ['dashboard.team.heroic'],  'can' => 'dashboard.team.heroic.view'],
        ['route' => 'dashboard.team.mythic',  'label' => 'Mythic Team',      'matches' => ['dashboard.team.mythic'],  'can' => 'dashboard.team.mythic.view'],
        ['route' => 'dashboard.keynight',     'label' => 'Keynight (M+)',    'matches' => ['dashboard.keynight'],     'can' => 'dashboard.keynight.view'],
    ];
    $navAdmin = [
        ['route' => 'events.index',                 'label' => 'Events',         'matches' => ['events.*'],                       'can' => 'events.create'],
        ['route' => 'admin.teams.index',            'label' => 'Team mapping',   'matches' => ['admin.teams.index', 'admin.teams.update'], 'can' => 'settings.manage'],
        ['route' => 'admin.sync.index',             'label' => 'Sync',           'matches' => ['admin.sync.*'],                   'can' => 'settings.manage'],
    ];
    $navLink = function (array $item) {
        $active = request()->routeIs(...$item['matches']);
        $base = 'block px-3 py-2 rounded text-sm transition';
        $cls = $active
            ? 'bg-accent/15 text-ink border-l-2 border-accent pl-[10px]'
            : 'text-muted hover:text-ink hover:bg-line/40';
        return [$base . ' ' . $cls, $active];
    };
    // Filter out nav items whose route name doesn't exist yet, so we
    // can list aspirational pages (like the upcoming Roster page)
    // without breaking the layout when their controller isn't wired
    // up. Once the route lands, the link appears automatically.
    $navPrimary = array_values(array_filter($navPrimary, fn ($i) => \Illuminate\Support\Facades\Route::has($i['route'])));
    $navAdmin   = array_values(array_filter($navAdmin,   fn ($i) => \Illuminate\Support\Facades\Route::has($i['route'])));
@endphp

<div class="flex min-h-screen">
    {{-- Sidebar. Fixed width on desktop; slides in from the left on mobile
         when the hamburger is toggled. The mobile overlay is a sibling
         div (not a wrapper) so the sidebar can stay sticky. --}}
    <aside
        class="fixed inset-y-0 left-0 z-30 w-60 bg-panel border-r border-line flex flex-col transform transition-transform md:translate-x-0 md:sticky md:top-0 md:h-screen"
        :class="navOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0'"
    >
        <div class="px-5 py-4 border-b border-line flex items-center justify-between">
            <a href="{{ route('dashboard') }}" class="font-semibold text-lg">Regenesis</a>
            <button type="button" class="md:hidden text-muted hover:text-ink" @click="navOpen = false" aria-label="Close menu">×</button>
        </div>

        <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-6 text-sm">
            <div class="space-y-1">
                @foreach ($navPrimary as $item)
                    @can($item['can'])
                        @php([$cls, $active] = $navLink($item))
                        <a href="{{ route($item['route']) }}" class="{{ $cls }}" @if ($active) aria-current="page" @endif>{{ $item['label'] }}</a>
                    @endcan
                @endforeach
            </div>

            <div>
                <div class="px-3 mb-2 text-[10px] uppercase tracking-wider text-muted/70">Admin</div>
                <div class="space-y-1">
                    @foreach ($navAdmin as $item)
                        @can($item['can'])
                            @php([$cls, $active] = $navLink($item))
                            <a href="{{ route($item['route']) }}" class="{{ $cls }}" @if ($active) aria-current="page" @endif>{{ $item['label'] }}</a>
                        @endcan
                    @endforeach
                </div>
            </div>
        </nav>

        <div class="px-4 py-3 border-t border-line text-xs text-muted">
            <div class="truncate">{{ auth()->user()->discord_username }}</div>
            <div class="flex items-center justify-between mt-1">
                <span class="uppercase text-[10px] tracking-wider">{{ auth()->user()->tier }}</span>
                <form method="POST" action="{{ route('logout') }}">@csrf
                    <button class="text-accent hover:underline">Sign out</button>
                </form>
            </div>
        </div>
    </aside>

    {{-- Mobile backdrop. Only renders when nav is open on small screens. --}}
    <div
        class="fixed inset-0 z-20 bg-black/50 md:hidden"
        x-show="navOpen" x-cloak
        @click="navOpen = false"
    ></div>

    <div class="flex-1 flex flex-col min-w-0">
        {{-- Mobile-only header strip with the hamburger. Desktop hides it
             entirely since the sidebar is permanently visible. --}}
        <header class="md:hidden border-b border-line px-4 py-3 flex items-center justify-between">
            <button type="button" class="text-muted hover:text-ink" @click="navOpen = true" aria-label="Open menu">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
            <span class="font-semibold">Regenesis</span>
            <span class="w-5"></span>
        </header>

        <main class="flex-1 px-6 py-8 max-w-6xl w-full">
            @yield('content')
        </main>

        <footer class="border-t border-line mt-16">
            <div class="px-6 py-4 text-xs text-muted flex items-center justify-between">
                <span>Regenesis-Silvermoon (EU)</span>
                @isset($lastSnapshot)
                    <span>
                        Last sync:
                        @if ($lastSnapshot)
                            {{ $lastSnapshot->captured_at->diffForHumans() }}
                            <span class="text-line">/</span>
                            {{ $lastSnapshot->member_count }} members tracked
                        @else
                            no data yet
                        @endif
                    </span>
                @endisset
            </div>
        </footer>
    </div>
</div>
</body>
</html>
