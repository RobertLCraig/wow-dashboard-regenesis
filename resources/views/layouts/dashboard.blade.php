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
    {{-- High-clarity display mode (per-user pref on users.display_mode).
         Phase A wires the body class + the always-on overrides (motion
         off, italics neutralised, single-column main, max line length).
         Widget-level changes (table -> stacked cards, charts -> lists)
         arrive in Phase B as widgets adopt the <x-clarity-table>
         component. --}}
    <style>
        body.mode-high-clarity { line-height: 1.7; letter-spacing: 0.005em; }
        body.mode-high-clarity main { max-width: 56rem; }
        body.mode-high-clarity *,
        body.mode-high-clarity *::before,
        body.mode-high-clarity *::after {
            animation-duration: 0.001ms !important;
            animation-iteration-count: 1 !important;
            transition-duration: 0.001ms !important;
        }
        body.mode-high-clarity em,
        body.mode-high-clarity i { font-style: normal; font-weight: 600; }

        /* Clarity-tabular: tables that opt-in to the stacked-card render
           in high-clarity mode. Standard mode leaves the table alone.
           Each row becomes a bordered card; each cell becomes a labelled
           line with the column name pulled from data-label. */
        body.mode-high-clarity table.clarity-tabular,
        body.mode-high-clarity table.clarity-tabular thead,
        body.mode-high-clarity table.clarity-tabular tbody,
        body.mode-high-clarity table.clarity-tabular tr,
        body.mode-high-clarity table.clarity-tabular td,
        body.mode-high-clarity table.clarity-tabular th {
            display: block;
            border: none;
            text-align: left !important;
        }
        body.mode-high-clarity table.clarity-tabular thead { display: none; }
        body.mode-high-clarity table.clarity-tabular tbody tr[data-row] {
            border: 2px solid #2a2a35;
            border-radius: 0.5rem;
            padding: 0.85rem 1rem;
            margin: 0 0.75rem 0.85rem;
            background: rgba(0,0,0,0.15);
        }
        body.mode-high-clarity table.clarity-tabular tbody tr[data-row]:first-child { margin-top: 0.85rem; }
        body.mode-high-clarity table.clarity-tabular tbody td {
            padding: 0.2rem 0;
            font-size: 0.875rem;
        }
        /* First cell of each row acts as the card heading. Convention:
           the consumer puts the primary identifier (Name with link) in
           the first cell with no data-label, so it renders bold + larger
           with no "Label:" prefix. */
        body.mode-high-clarity table.clarity-tabular tbody tr[data-row] td:first-child {
            font-size: 0.95rem;
            font-weight: 600;
            margin-bottom: 0.4rem;
            padding-bottom: 0.4rem;
            border-bottom: 1px solid #2a2a35;
        }
        body.mode-high-clarity table.clarity-tabular tbody td[data-label]::before {
            content: attr(data-label) ":";
            display: inline-block;
            min-width: 9ch;
            color: #7a7a8c;
            font-weight: 500;
            margin-right: 0.5rem;
            text-transform: none;
            letter-spacing: 0;
        }
        /* The empty-message row is purely a search-no-match indicator;
           it should look like an inline note, not a card. */
        body.mode-high-clarity table.clarity-tabular tbody tr[data-empty-message] {
            border: none;
            background: none;
            margin: 0;
            padding: 0.5rem 0;
            font-style: italic;
            color: #7a7a8c;
        }
        body.mode-high-clarity table.clarity-tabular tbody tr[data-empty-message]::before { content: none; }
        body.mode-high-clarity table.clarity-tabular tbody tr[data-empty-message] td::before { content: none; }
    </style>
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
@php
    $displayMode = auth()->user()?->display_mode ?? \App\Models\User::DISPLAY_STANDARD;
    $bodyMode = $displayMode === \App\Models\User::DISPLAY_HIGH_CLARITY ? 'mode-high-clarity' : 'mode-standard';
@endphp
<body class="bg-bg text-ink font-sans antialiased min-h-screen {{ $bodyMode }}" x-data="{ navOpen: false }">
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
        ['route' => 'reports.index',          'label' => 'Reports',          'matches' => ['reports.*'],              'can' => 'reports.view'],
        ['route' => 'dashboard.team.heroic',  'label' => 'Heroic Team',      'matches' => ['dashboard.team.heroic'],  'can' => 'dashboard.team.heroic.view'],
        ['route' => 'dashboard.team.mythic',  'label' => 'Mythic Team',      'matches' => ['dashboard.team.mythic'],  'can' => 'dashboard.team.mythic.view'],
        ['route' => 'dashboard.keynight',     'label' => 'Keynight (M+)',    'matches' => ['dashboard.keynight'],     'can' => 'dashboard.keynight.view'],
    ];
    $navAdmin = [
        ['route' => 'events.index',                 'label' => 'Events',         'matches' => ['events.*'],                       'can' => 'events.create'],
        ['route' => 'admin.teams.index',            'label' => 'Team mapping',   'matches' => ['admin.teams.index', 'admin.teams.update'], 'can' => 'settings.manage'],
        ['route' => 'admin.teams.schedule.index',   'label' => 'Team schedule',  'matches' => ['admin.teams.schedule.*'],         'can' => 'settings.manage'],
        ['route' => 'admin.sync.index',             'label' => 'Sync',           'matches' => ['admin.sync.*'],                   'can' => 'settings.manage'],
        ['route' => 'admin.webhooks.index',         'label' => 'Webhooks',       'matches' => ['admin.webhooks.*'],               'can' => 'settings.manage'],
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
                        @php
                            [$cls, $active] = $navLink($item);
                        @endphp
                        <a href="{{ route($item['route']) }}" class="{{ $cls }}" @if ($active) aria-current="page" @endif>{{ $item['label'] }}</a>
                    @endcan
                @endforeach
            </div>

            <div>
                <div class="px-3 mb-2 text-[10px] uppercase tracking-wider text-muted/70">Admin</div>
                <div class="space-y-1">
                    @foreach ($navAdmin as $item)
                        @can($item['can'])
                            @php
                            [$cls, $active] = $navLink($item);
                        @endphp
                            <a href="{{ route($item['route']) }}" class="{{ $cls }}" @if ($active) aria-current="page" @endif>{{ $item['label'] }}</a>
                        @endcan
                    @endforeach
                </div>
            </div>
        </nav>

        <div class="px-4 py-3 border-t border-line text-xs text-muted space-y-2">
            <div class="truncate">{{ auth()->user()->discord_username }}</div>
            <div class="flex items-center justify-between">
                <span class="uppercase text-[10px] tracking-wider">{{ auth()->user()->tier }}</span>
                <form method="POST" action="{{ route('logout') }}">@csrf
                    <button class="text-accent hover:underline">Sign out</button>
                </form>
            </div>
            {{-- High-clarity toggle. Single-form POST per click; no JS.
                 The label flips to "Standard view" once high-clarity is
                 active so the action reads as the destination, not the
                 current state. --}}
            <form method="POST" action="{{ route('preferences.display') }}" class="pt-2 border-t border-line/60">
                @csrf
                @php
                    $hc = $displayMode === \App\Models\User::DISPLAY_HIGH_CLARITY;
                @endphp
                <input type="hidden" name="display_mode"
                       value="{{ $hc ? \App\Models\User::DISPLAY_STANDARD : \App\Models\User::DISPLAY_HIGH_CLARITY }}">
                <button type="submit"
                        class="w-full text-left text-[11px] text-muted hover:text-ink transition flex items-center justify-between"
                        title="{{ $hc ? 'Switch back to the default dashboard layout' : 'Single-column, big spacing, no motion' }}">
                    <span>{{ $hc ? 'Standard view' : 'High-clarity view' }}</span>
                    <span class="text-[10px] uppercase tracking-wider {{ $hc ? 'text-emerald-300' : 'text-muted/60' }}">
                        {{ $hc ? 'on' : 'off' }}
                    </span>
                </button>
            </form>
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

        <main class="flex-1 px-6 py-8 max-w-screen-2xl w-full">
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
