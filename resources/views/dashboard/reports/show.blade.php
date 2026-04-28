@extends('layouts.dashboard')

@section('title', $report->title)

@section('content')
    <section class="bg-panel border border-line rounded-lg overflow-hidden mb-4">
        <div x-data="{ explain: false }">
            <header class="px-5 py-4 flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-xl font-semibold flex items-center gap-2">
                        <span>{{ $report->title }}</span>
                        <x-explainer-toggle />
                    </h1>
                    <p class="text-sm text-muted mt-1">
                        {{ $report->start_time?->format('D d M Y H:i') ?? '-' }}
                        @if ($report->zone_name)
                            <span class="text-line">|</span>
                            {{ $report->zone_name }}
                        @endif
                        @if ($report->owner_name)
                            <span class="text-line">|</span>
                            Logged by {{ $report->owner_name }}
                        @endif
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <a href="{{ route('reports.index') }}" class="text-sm text-muted hover:text-ink">&larr; All logs</a>
                    <a href="{{ $report->jumpUrl() }}" target="_blank" rel="noopener noreferrer"
                       class="text-sm px-3 py-1.5 rounded border border-line bg-bg hover:bg-panel">
                        Open on WCL &rarr;
                    </a>
                </div>
            </header>
            <x-explainer-panel title="Reading this log">
                <p>
                    Each section below is one boss attempt (a "pull"), straight from
                    the Warcraft Logs report. The strip down the left tells you the
                    outcome at a glance: green for a kill, red for a wipe (with the
                    boss's remaining HP at the moment the pull ended). Click the
                    <span class="inline-flex w-4 h-4 items-center justify-center rounded-full border border-line text-[10px] font-semibold align-middle">?</span>
                    next to any column heading for what that column actually means.
                    Each table also has its own search box and sortable columns.
                </p>
            </x-explainer-panel>
        </div>
    </section>

    @if ($fights->isEmpty())
        <div class="bg-panel border border-line rounded-lg p-8 text-center text-muted text-sm">
            Fights haven't been imported for this report yet. The next WCL sync will backfill them.
        </div>
    @else
        @foreach ($fights as $fight)
            @php
                $tone = $fight->kill ? 'border-emerald-700/50 bg-emerald-950/10' : 'border-rose-700/40 bg-rose-950/10';
                $duration = $fight->duration_ms ? gmdate('i:s', (int) ($fight->duration_ms / 1000)) : null;
                $rolesPresent = $fight->parses->pluck('role')->filter()->unique()->values();
            @endphp
            <section x-data="sortableTable()"
                     class="rounded-lg border {{ $tone }} mb-4 overflow-hidden">
                <header class="px-5 py-3 border-b border-line flex items-center justify-between gap-3 flex-wrap">
                    <div>
                        <h2 class="font-semibold">
                            #{{ $fight->fight_id }} - {{ $fight->name }}
                            <span class="ml-2 text-xs uppercase tracking-wider text-muted">
                                {{ \App\Models\WclFight::difficultyLabel($fight->difficulty) }}
                            </span>
                        </h2>
                        <p class="text-xs text-muted mt-0.5">
                            @if ($fight->kill)
                                <span class="text-emerald-300">Kill</span>
                            @else
                                <span class="text-rose-300">Wipe</span>
                                @if ($fight->best_percentage !== null)
                                    @ {{ rtrim(rtrim(number_format($fight->best_percentage, 2), '0'), '.') }}%
                                @endif
                            @endif
                            @if ($duration)
                                <span class="text-line">|</span> {{ $duration }}
                            @endif
                        </p>
                    </div>
                    @if ($fight->parses->isNotEmpty())
                        <div class="flex items-center gap-2 flex-wrap justify-end">
                            @if ($rolesPresent->count() > 1)
                                <select x-model="filters.role"
                                        class="bg-bg border border-line rounded px-2 py-1 text-xs text-muted">
                                    <option value="">All roles</option>
                                    @foreach ($rolesPresent as $r)
                                        <option value="{{ $r }}">{{ ucfirst($r) }}</option>
                                    @endforeach
                                </select>
                            @endif
                            <input type="text" x-model="search"
                                   placeholder="Search player or class..."
                                   class="bg-bg border border-line rounded px-2 py-1 text-xs w-48 placeholder:text-muted">
                        </div>
                    @endif
                </header>

                @if ($fight->parses->isEmpty())
                    <div class="px-5 py-3 text-xs text-muted italic">No per-actor data captured for this pull.</div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm" x-data="{ openCol: null }">
                            <thead>
                                <tr class="text-left text-xs uppercase tracking-wider text-muted">
                                    <th class="px-5 py-2 font-medium cursor-pointer select-none hover:text-ink"
                                        @click="sortBy('player')">
                                        Player <span class="text-muted" x-text="sortIcon('player')"></span>
                                        <x-column-explainer-toggle col="player" />
                                    </th>
                                    <th class="px-2 py-2 font-medium cursor-pointer select-none hover:text-ink"
                                        @click="sortBy('class')">
                                        Class <span class="text-muted" x-text="sortIcon('class')"></span>
                                        <x-column-explainer-toggle col="class" />
                                    </th>
                                    <th class="px-2 py-2 font-medium cursor-pointer select-none hover:text-ink"
                                        @click="sortBy('role')">
                                        Role <span class="text-muted" x-text="sortIcon('role')"></span>
                                        <x-column-explainer-toggle col="role" />
                                    </th>
                                    <th class="px-2 py-2 font-medium cursor-pointer select-none hover:text-ink text-right"
                                        @click="sortBy('mps')">
                                        Per second <span class="text-muted" x-text="sortIcon('mps')"></span>
                                        <x-column-explainer-toggle col="mps" />
                                    </th>
                                    <th class="px-2 py-2 font-medium cursor-pointer select-none hover:text-ink text-right"
                                        @click="sortBy('parse')">
                                        Parse <span class="text-muted" x-text="sortIcon('parse')"></span>
                                        <x-column-explainer-toggle col="parse" />
                                    </th>
                                    <th class="px-2 py-2 font-medium cursor-pointer select-none hover:text-ink text-right"
                                        @click="sortBy('bracket')">
                                        Bracket <span class="text-muted" x-text="sortIcon('bracket')"></span>
                                        <x-column-explainer-toggle col="bracket" />
                                    </th>
                                    <th class="px-2 py-2 font-medium cursor-pointer select-none hover:text-ink text-right"
                                        @click="sortBy('ilvl')">
                                        ilvl <span class="text-muted" x-text="sortIcon('ilvl')"></span>
                                        <x-column-explainer-toggle col="ilvl" />
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr x-show="openCol !== null" x-cloak class="border-t border-line bg-bg/40">
                                    <td colspan="7"
                                        class="px-5 py-3 text-xs text-muted leading-relaxed normal-case tracking-normal font-normal">
                                        <template x-if="openCol === 'player'">
                                            <div>
                                                <span class="block text-ink font-semibold mb-1">Player</span>
                                                The character name as it appears in the log. The
                                                <span class="text-ink font-medium">guild</span> tag means we matched
                                                the name to a roster member, so this pull counts toward their
                                                attendance and parses on their character page.
                                            </div>
                                        </template>
                                        <template x-if="openCol === 'class'">
                                            <div>
                                                <span class="block text-ink font-semibold mb-1">Class</span>
                                                The character's WoW class, colour-coded to match the in-game class
                                                colours.
                                            </div>
                                        </template>
                                        <template x-if="openCol === 'role'">
                                            <div>
                                                <span class="block text-ink font-semibold mb-1">Role</span>
                                                The role the player filled on this specific pull (DPS, Healer, or
                                                Tank). Taken from WCL's rankings bucket when the pull is ranked,
                                                otherwise inferred by comparing damage and healing output and
                                                constrained by what the class can actually fill (a Mage will never
                                                show as Healer).
                                            </div>
                                        </template>
                                        <template x-if="openCol === 'mps'">
                                            <div>
                                                <span class="block text-ink font-semibold mb-1">Per second</span>
                                                DPS for damage dealers and tanks, HPS for healers, averaged over
                                                the full pull duration. Higher is better, but compare like for
                                                like: a 4-minute kill and a 90-second wipe will produce very
                                                different numbers for the same player.
                                            </div>
                                        </template>
                                        <template x-if="openCol === 'parse'">
                                            <div>
                                                <span class="block text-ink font-semibold mb-1">Parse</span>
                                                WCL's percentile rank for this pull against every logged attempt
                                                in the world for the same spec, fight, and difficulty.
                                                <span class="text-amber-300">95+</span> is gold / world-class,
                                                <span class="text-fuchsia-300">75+</span> is purple / very good,
                                                50 is the median. Blank means WCL hadn't ranked the pull at sync
                                                time, which is normal for wipes and very short pulls.
                                            </div>
                                        </template>
                                        <template x-if="openCol === 'bracket'">
                                            <div>
                                                <span class="block text-ink font-semibold mb-1">Bracket</span>
                                                The same percentile as Parse, but compared only to players in a
                                                similar item-level bracket. This is the gear-adjusted score: if
                                                your Parse is low but your Bracket is high, you're playing your
                                                spec well, you're just outgeared by the leaderboard.
                                            </div>
                                        </template>
                                        <template x-if="openCol === 'ilvl'">
                                            <div>
                                                <span class="block text-ink font-semibold mb-1">ilvl</span>
                                                Equipped item level at the time of the pull, as reported by the
                                                log uploader's combat log.
                                            </div>
                                        </template>
                                    </td>
                                </tr>
                                @foreach ($fight->parses as $p)
                                    @php $cls = 'cls-' . strtoupper($p->actor_class ?? ''); @endphp
                                    <tr class="border-t border-line"
                                        data-row
                                        data-filter-role="{{ $p->role ?? '' }}">
                                        <td class="px-5 py-1.5"
                                            data-sort-key="player"
                                            data-sort-value="{{ strtolower($p->actor_name) }}">
                                            <span class="{{ $cls }}">{{ $p->actor_name }}</span>
                                            @if ($p->member_id)
                                                <span class="text-[10px] uppercase tracking-wider text-muted ml-2">guild</span>
                                            @endif
                                        </td>
                                        <td class="px-2 py-1.5 text-muted text-xs"
                                            data-sort-key="class"
                                            data-sort-value="{{ strtolower($p->actor_class ?? '') }}">
                                            {{ $p->actor_class ?? '-' }}
                                        </td>
                                        <td class="px-2 py-1.5 text-muted text-xs"
                                            data-sort-key="role"
                                            data-sort-value="{{ $p->role ?? '' }}">
                                            {{ ucfirst($p->role ?? '-') }}
                                        </td>
                                        <td class="px-2 py-1.5 font-mono text-right"
                                            data-sort-key="mps"
                                            data-sort-value="{{ $p->metric_per_second !== null ? (float) $p->metric_per_second : '' }}">
                                            {{ $p->metric_per_second !== null ? number_format($p->metric_per_second, 0) : '-' }}
                                        </td>
                                        <td class="px-2 py-1.5 text-right"
                                            data-sort-key="parse"
                                            data-sort-value="{{ $p->parse_percentile ?? '' }}">
                                            <x-parse-pill :percentile="$p->parse_percentile" />
                                        </td>
                                        <td class="px-2 py-1.5 text-right"
                                            data-sort-key="bracket"
                                            data-sort-value="{{ $p->bracket_percentile ?? '' }}">
                                            <x-parse-pill :percentile="$p->bracket_percentile" />
                                        </td>
                                        <td class="px-2 py-1.5 font-mono text-right"
                                            data-sort-key="ilvl"
                                            data-sort-value="{{ $p->item_level ?? '' }}">
                                            {{ $p->item_level ?? '-' }}
                                        </td>
                                    </tr>
                                @endforeach
                                <tr data-empty-message style="display:none">
                                    <td colspan="7" class="px-5 py-4 text-center text-muted text-xs italic">
                                        No matches.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endforeach
    @endif
@endsection
