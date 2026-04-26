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
<body class="bg-bg text-ink font-sans antialiased min-h-screen">
<header class="border-b border-line">
    <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
        <div class="flex items-center gap-6">
            <a href="{{ route('dashboard') }}" class="font-semibold text-lg">Regenesis</a>
            <nav class="hidden md:flex items-center gap-4 text-sm text-muted">
                <a href="{{ route('dashboard') }}" class="hover:text-ink">Dashboard</a>
                <span class="text-line">|</span>
                <a href="{{ route('events.index') }}" class="hover:text-ink">Events</a>
                <span class="text-line">|</span>
                <a href="{{ route('admin.teams.index') }}" class="hover:text-ink">Team mapping</a>
                <span class="text-line">|</span>
                <a href="{{ route('admin.sync.index') }}" class="hover:text-ink">Sync</a>
                <span class="text-line">|</span>
                <span class="text-line">Roster (soon)</span>
            </nav>
        </div>
        <div class="flex items-center gap-3 text-sm text-muted">
            <span>{{ auth()->user()->discord_username }} <span class="text-line">/</span> <span class="uppercase text-xs tracking-wider">{{ auth()->user()->tier }}</span></span>
            <form method="POST" action="{{ route('logout') }}">@csrf
                <button class="text-accent hover:underline">Sign out</button>
            </form>
        </div>
    </div>
</header>

<main class="max-w-7xl mx-auto px-6 py-8">
    @yield('content')
</main>

<footer class="border-t border-line mt-16">
    <div class="max-w-7xl mx-auto px-6 py-4 text-xs text-muted flex items-center justify-between">
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
</body>
</html>
