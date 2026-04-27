<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Dashboard') | Regenesis</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('img/brand/phoenix-mark.png') }}">
    {{-- Tailwind via CDN keeps the deploy story simple while widgets
         are still being added. We'll move to a proper Vite build once
         the structure stabilises (probably when we add the event
         creator form). --}}
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>[x-cloak] { display: none !important; }</style>
    {{-- Reusable Alpine factory for sortable + searchable + filterable tables.
         Wrap a table region with x-data="sortableTable()", mark data rows with
         data-row, give each sortable cell data-sort-key="X" and (optionally)
         data-sort-value="..." for a non-text sort key. Headers call sortBy('X')
         and render an indicator with sortIcon('X'). Bind a no-name search
         input via x-model="search". For category filters, give rows a
         data-filter-{key}="value" attr and bind a select with
         x-model="filters.{key}" (empty string = no filter). Trailing rows that
         should never sort or hide (e.g. "add new" rows) get data-table-trailing. --}}
    <script>
        function sortableTable() {
            return {
                search: '',
                filters: {},
                sortKey: null,
                sortDir: 'asc',
                init() {
                    this.$watch('search', () => this.applyFilter());
                    this.$watch('filters', () => this.applyFilter(), { deep: true });
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
                rowMatchesFilters(row) {
                    for (const [key, val] of Object.entries(this.filters)) {
                        if (val === '' || val == null) continue;
                        if (row.getAttribute(`data-filter-${key}`) !== val) return false;
                    }
                    return true;
                },
                hasActiveFilter() {
                    if (this.search.trim() !== '') return true;
                    return Object.values(this.filters).some(v => v !== '' && v != null);
                },
                applyFilter() {
                    const q = this.search.trim().toLowerCase();
                    const rows = this.$root.querySelectorAll('tbody tr[data-row]');
                    let visible = 0;
                    rows.forEach(r => {
                        const matchSearch = q === '' || this.rowText(r).includes(q);
                        const matchFilters = this.rowMatchesFilters(r);
                        const match = matchSearch && matchFilters;
                        r.style.display = match ? '' : 'none';
                        if (match) visible++;
                    });
                    const empty = this.$root.querySelector('[data-empty-message]');
                    if (empty) empty.style.display = (visible === 0 && rows.length > 0 && this.hasActiveFilter()) ? '' : 'none';
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
                        // Accent colour comes from the body's theme class
                        // (theme-discord / theme-phoenix), set as an RGB
                        // triple in --c-accent so Tailwind's opacity
                        // utilities (bg-accent/15 etc) keep working.
                        accent: 'rgb(var(--c-accent) / <alpha-value>)',
                    },
                },
            },
        };
    </script>
    {{-- Theme accent CSS variables. Default at :root keeps the
         landing/auth pages on Discord blue; body theme classes
         override per logged-in user preference. --}}
    <style>
        :root            { --c-accent: 88 101 242; }   /* discord blurple */
        body.theme-discord { --c-accent: 88 101 242; }
        body.theme-phoenix { --c-accent: 168 38 46; }  /* phoenix red */
    </style>
    {{-- Display mode (users.display_mode). Three steps stack additively:

         standard      no overrides at all (baseline)
         clear         + typography nudge (bigger text, more padding,
                       no italics, no motion, lifted muted contrast)
         high_clarity  + structural overrides (single-column grids,
                       tables collapse into stacked cards)

         CSS shape: the typography layer applies to both `clear` and
         `high-clarity` (so users can step UP without losing earlier
         gains); the structural layer applies to `high-clarity` only.
         Standard mode pays no CSS cost. --}}
    <style>
        /* --- Layer 1: typography nudge (clear + high-clarity) --- */
        body.mode-clear, body.mode-high-clarity {
            line-height: 1.7;
            letter-spacing: 0.01em;
        }
        body.mode-clear *, body.mode-clear *::before, body.mode-clear *::after,
        body.mode-high-clarity *, body.mode-high-clarity *::before, body.mode-high-clarity *::after {
            animation-duration: 0.001ms !important;
            animation-iteration-count: 1 !important;
            transition-duration: 0.001ms !important;
        }
        body.mode-clear em, body.mode-clear i,
        body.mode-high-clarity em, body.mode-high-clarity i {
            font-style: normal;
            font-weight: 600;
        }
        body.mode-clear .text-xs,        body.mode-high-clarity .text-xs        { font-size: 0.8125rem; }
        body.mode-clear .text-sm,        body.mode-high-clarity .text-sm        { font-size: 0.9375rem; }
        body.mode-clear .text-base,      body.mode-high-clarity .text-base      { font-size: 1.0625rem; }
        body.mode-clear .text-lg,        body.mode-high-clarity .text-lg        { font-size: 1.1875rem; }
        body.mode-clear .text-xl,        body.mode-high-clarity .text-xl        { font-size: 1.375rem;  }
        body.mode-clear .text-\[10px\],  body.mode-high-clarity .text-\[10px\]  { font-size: 0.6875rem; }
        body.mode-clear .text-\[11px\],  body.mode-high-clarity .text-\[11px\]  { font-size: 0.75rem;   }
        body.mode-clear table tbody td, body.mode-clear table thead th,
        body.mode-high-clarity table tbody td, body.mode-high-clarity table thead th {
            padding-top: 0.65rem;
            padding-bottom: 0.65rem;
        }
        body.mode-clear section > header,
        body.mode-high-clarity section > header { padding-top: 1rem; padding-bottom: 1rem; }
        body.mode-clear .text-muted,
        body.mode-high-clarity .text-muted { color: #9494a5; }

        /* --- Layer 2: structural overrides (high-clarity only) --- */
        /* Bigger typographic step on top of the layer-1 bump. */
        body.mode-high-clarity { line-height: 1.85; }
        body.mode-high-clarity .text-xs        { font-size: 0.875rem;  } /* 14px */
        body.mode-high-clarity .text-sm        { font-size: 1rem;      } /* 16px */
        body.mode-high-clarity .text-base      { font-size: 1.125rem;  } /* 18px */
        body.mode-high-clarity .text-lg        { font-size: 1.25rem;   } /* 20px */
        body.mode-high-clarity .text-xl        { font-size: 1.5rem;    } /* 24px */
        body.mode-high-clarity .text-\[10px\]  { font-size: 0.75rem;   }
        body.mode-high-clarity .text-\[11px\]  { font-size: 0.8125rem; }

        /* Force every responsive grid to single-column so adjacent
           widgets stack instead of sitting side-by-side. The grid still
           lays out as flow, just one item per row with generous gap.
           Opt-out: any widget whose internal grid is small enough to
           genuinely belong on one line (e.g. a row of 4 KPI cards)
           can wear .clarity-keep-grid to skip the override. */
        body.mode-high-clarity .grid:not(.clarity-keep-grid) {
            display: flex !important;
            flex-direction: column;
            gap: 1.75rem;
        }

        /* Tables that opted into clarity-tabular collapse into a
           stacked-card list. Each row becomes a bordered card; each
           cell becomes a labelled line via data-label + ::before. */
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
        body.mode-high-clarity table.clarity-tabular tbody td { padding: 0.3rem 0; }
        body.mode-high-clarity table.clarity-tabular tbody tr[data-row] td:first-child {
            font-size: 1.1em;
            font-weight: 600;
            margin-bottom: 0.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #2a2a35;
        }
        body.mode-high-clarity table.clarity-tabular tbody td[data-label]::before {
            content: attr(data-label) ":";
            display: inline-block;
            min-width: 9ch;
            color: #9494a5;
            font-weight: 500;
            margin-right: 0.6rem;
            text-transform: none;
            letter-spacing: 0;
        }
        body.mode-high-clarity table.clarity-tabular tbody tr[data-empty-message] {
            border: none;
            background: none;
            margin: 0;
            padding: 0.5rem 0;
            font-style: normal;
            font-weight: 600;
            color: #9494a5;
        }
        body.mode-high-clarity table.clarity-tabular tbody tr[data-empty-message]::before { content: none; }
        body.mode-high-clarity table.clarity-tabular tbody tr[data-empty-message] td::before { content: none; }

        /* --- High-clarity interaction polish --- */
        /* Bump tiny interactive elements toward a 32px+ tap target so
           the GM (and anyone else with diplopia / fine-motor issues)
           can hit them without accidentally clicking a neighbour.
           Targets the small icon buttons used by the explainer-toggle
           component (16x16 in standard), the drag handle + up/down
           buttons in dashboard edit mode (24x24), and assorted
           chevron / close buttons across the admin pages. Selector
           matches Tailwind's w-N h-N utilities so we don't have to
           walk every component. */
        body.mode-high-clarity button.w-4.h-4,
        body.mode-high-clarity button.w-5.h-5,
        body.mode-high-clarity button.w-6.h-6 {
            width: 2rem !important;
            height: 2rem !important;
            font-size: 0.875rem !important;
        }

        /* Visible focus ring on every keyboard-focusable element.
           Outline (not box-shadow) so it isn't suppressed by sibling
           overflow:hidden containers. */
        body.mode-high-clarity :focus-visible {
            outline: 2px solid rgb(var(--c-accent));
            outline-offset: 2px;
            border-radius: 0.25rem;
        }

        /* Search inputs across widgets get a bit more vertical room
           and a slightly larger placeholder so the input field reads
           as a proper edit target rather than a thin strip. */
        body.mode-high-clarity input[type="text"],
        body.mode-high-clarity input[type="search"],
        body.mode-high-clarity select {
            padding-top: 0.45rem;
            padding-bottom: 0.45rem;
            font-size: 0.9375rem;
        }

        /* Dashboard-layout edit-mode chrome reads better at this
           size; the drag handle in particular needs a meatier hit
           area than the default 1rem character. */
        body.mode-high-clarity .js-drag-handle {
            font-size: 1.25rem;
            padding: 0 0.25rem;
        }
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
    $bodyMode = match ($displayMode) {
        \App\Models\User::DISPLAY_HIGH_CLARITY => 'mode-high-clarity',
        \App\Models\User::DISPLAY_CLEAR        => 'mode-clear',
        default                                => 'mode-standard',
    };
    $userTheme = auth()->user()?->theme ?? \App\Models\User::THEME_DISCORD;
    $bodyTheme = $userTheme === \App\Models\User::THEME_PHOENIX ? 'theme-phoenix' : 'theme-discord';
@endphp
<body class="bg-bg text-ink font-sans antialiased min-h-screen {{ $bodyMode }} {{ $bodyTheme }}" x-data="{ navOpen: false }">
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
            <a href="{{ route('dashboard') }}" class="font-semibold text-lg flex items-center gap-2">
                <x-icon kind="brand" name="phoenix-mark" :size="28" alt="Regenesis" />
                <span>Regenesis</span>
            </a>
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
                @php
                    // big6 doesn't have a dedicated badge yet (see
                    // accessibility/brand notes in next-session.md
                    // section 6.5); alias to officer for now so the
                    // sidebar always shows something rather than a
                    // broken image.
                    $tierName = auth()->user()->tier;
                    $tierBadge = match ($tierName) {
                        'gm'        => 'gm',
                        'officer'   => 'officer',
                        'big6'      => 'officer',
                        'moderator' => 'moderator',
                        default     => null,
                    };
                @endphp
                <span class="flex items-center gap-1.5">
                    @if ($tierBadge)
                        <x-icon kind="guild-role" :name="$tierBadge" :size="16" />
                    @endif
                    <span class="uppercase text-[10px] tracking-wider">{{ $tierName }}</span>
                </span>
                <form method="POST" action="{{ route('logout') }}">@csrf
                    <button class="text-accent hover:underline">Sign out</button>
                </form>
            </div>
            {{-- View clarity dial. Three steps, each a JS-free POST.
                 Standard = no overrides. Clear = typography nudge.
                 High = stacked-card tables + single-column flow.
                 Each step layers on top of the previous; click the
                 active one to leave it as-is (POST is idempotent). --}}
            @php
                $clarityOptions = [
                    \App\Models\User::DISPLAY_STANDARD     => ['label' => 'Standard', 'hint' => 'Default dashboard, no overrides.'],
                    \App\Models\User::DISPLAY_CLEAR        => ['label' => 'Clear',    'hint' => 'Bigger text, more spacing, no motion.'],
                    \App\Models\User::DISPLAY_HIGH_CLARITY => ['label' => 'High',     'hint' => 'Single column, tables become stacked cards.'],
                ];
            @endphp
            <div class="pt-2 border-t border-line/60">
                <div class="text-[10px] uppercase tracking-wider text-muted/60 mb-1">View clarity</div>
                <div class="flex border border-line rounded overflow-hidden mb-3" role="group" aria-label="View clarity">
                    @foreach ($clarityOptions as $value => $opt)
                        @php $active = $displayMode === $value; @endphp
                        <form method="POST" action="{{ route('preferences.display') }}" class="flex-1">
                            @csrf
                            <input type="hidden" name="display_mode" value="{{ $value }}">
                            <button type="submit"
                                    title="{{ $opt['hint'] }}"
                                    aria-pressed="{{ $active ? 'true' : 'false' }}"
                                    class="w-full text-[11px] py-1.5 transition
                                           {{ $active
                                               ? 'bg-accent/20 text-ink font-medium'
                                               : 'text-muted hover:text-ink hover:bg-line/40' }}">
                                {{ $opt['label'] }}
                            </button>
                        </form>
                    @endforeach
                </div>

                {{-- Theme picker. Orthogonal to view clarity:
                     theme controls accent colour, clarity controls
                     layout. Two-state for now (Discord blurple vs
                     phoenix red); the column type allows extending
                     to more themes without schema work. --}}
                @php
                    $themeOptions = [
                        \App\Models\User::THEME_DISCORD => ['label' => 'Discord', 'hint' => 'Default Discord blurple accent.'],
                        \App\Models\User::THEME_PHOENIX => ['label' => 'Phoenix', 'hint' => 'Phoenix-red accent that matches the guild logo.'],
                    ];
                @endphp
                <div class="text-[10px] uppercase tracking-wider text-muted/60 mb-1">Theme</div>
                <div class="flex border border-line rounded overflow-hidden" role="group" aria-label="Theme">
                    @foreach ($themeOptions as $value => $opt)
                        @php $active = $userTheme === $value; @endphp
                        <form method="POST" action="{{ route('preferences.theme') }}" class="flex-1">
                            @csrf
                            <input type="hidden" name="theme" value="{{ $value }}">
                            <button type="submit"
                                    title="{{ $opt['hint'] }}"
                                    aria-pressed="{{ $active ? 'true' : 'false' }}"
                                    class="w-full text-[11px] py-1.5 transition
                                           {{ $active
                                               ? 'bg-accent/20 text-ink font-medium'
                                               : 'text-muted hover:text-ink hover:bg-line/40' }}">
                                {{ $opt['label'] }}
                            </button>
                        </form>
                    @endforeach
                </div>
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
