@extends('layouts.dashboard')

@section('title', $member->name)

{{-- Wowhead's power.js renders inline tooltips on any element with a
     data-wowhead attribute. Lightweight and async so it doesn't block
     paint; only loaded on the character page where the BiS comparison
     uses it. --}}
@push('head')
    <script src="https://wow.zamimg.com/widgets/power.js" defer></script>
@endpush

@section('content')
    @php
        $cls = 'cls-' . strtoupper($member->class ?? '');
        $teamValues = $member->teamValues();
        $teamLabel = $teamValues
            ? implode(' | ', array_map(fn ($t) => \App\Models\TeamMapping::teamLabel($t), $teamValues))
            : null;
        $hasOverride = $member->hasTeamOverride();
        $rankDerivedTeam = app(\App\Services\Teams\TeamResolver::class)->forRank($member->rank_name);
        $statusTone = match ($member->status) {
            'active' => 'border-emerald-700/50 text-emerald-300',
            'left' => 'border-amber-700/50 text-amber-300',
            'banned' => 'border-rose-700/50 text-rose-300',
            default => 'border-line text-muted',
        };
    @endphp

    @if (session('status'))
        <div class="mb-4 p-3 rounded border border-green-700 bg-green-900/30 text-sm text-green-200">
            {{ session('status') }}
        </div>
    @endif

    <div class="flex items-start justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-semibold {{ $cls }}">{{ $member->name }}</h1>
            <div class="text-sm text-muted mt-1 flex items-center gap-2 flex-wrap">
                @if ($member->level)
                    <span>L{{ $member->level }}</span>
                    <span class="text-line">|</span>
                @endif
                @if ($member->class)
                    <span>{{ ucfirst(strtolower($member->class)) }}</span>
                    <span class="text-line">|</span>
                @endif
                @if ($member->rank_name)
                    <span>{{ $member->rank_name }}</span>
                    <span class="text-line">|</span>
                @endif
                @if ($teamLabel)
                    <span>
                        {{ $teamLabel }}
                        @if ($hasOverride)
                            <span class="ml-1 text-[10px] uppercase tracking-wider text-amber-300/80 border border-amber-700/40 rounded px-1 py-0.5"
                                  title="Officer-set; ignores the rank-to-team mapping under /admin/teams">override</span>
                        @endif
                    </span>
                    <span class="text-line">|</span>
                @endif
                <span class="text-[10px] uppercase tracking-wider px-1.5 py-0.5 rounded border {{ $statusTone }}">
                    {{ $member->status }}
                </span>
            </div>
            @if ($member->main)
                <p class="text-xs text-muted mt-1">
                    Alt of <a href="{{ route('character.show', $member->main->name) }}" class="text-accent hover:underline">{{ $member->main->name }}</a>
                </p>
            @endif
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('roster.index') }}" class="text-sm text-muted hover:text-ink">&larr; Roster</a>
            <x-character-links :member="$member" />
        </div>
    </div>

    {{-- Top row: snapshot summary cards (one per data source) --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        @php
            $sourceLabels = [
                'grm' => 'GRM (in-game)',
                'raiderio' => 'Raider.IO',
                'wowaudit' => 'Wowaudit',
            ];
        @endphp
        @foreach ($sourceLabels as $source => $label)
            @php
                $entry = $latestSnapshots[$source] ?? null;
                $snap = $entry ? $entry['member_snapshot'] : null;
                $captured = $entry ? $entry['snapshot']?->captured_at : null;
            @endphp
            <section class="bg-panel border border-line rounded-lg p-4">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-xs uppercase tracking-wider text-muted">{{ $label }}</h2>
                    <span class="text-[10px] text-muted">
                        {{ $captured?->diffForHumans() ?? 'never' }}
                    </span>
                </div>
                @if ($snap)
                    <div class="mt-2 grid grid-cols-2 gap-2 text-sm">
                        <div>
                            <div class="text-[10px] uppercase tracking-wider text-muted">ilvl</div>
                            <div class="font-mono">{{ $snap->ilvl ?? '-' }}</div>
                        </div>
                        @if ($source === 'raiderio')
                            <div>
                                <div class="text-[10px] uppercase tracking-wider text-muted">RIO</div>
                                <div class="font-mono">{{ $snap->mplus_score !== null ? number_format($snap->mplus_score, 0) : '-' }}</div>
                            </div>
                            <div>
                                <div class="text-[10px] uppercase tracking-wider text-muted">Weekly key</div>
                                <div class="font-mono">
                                    <x-weekly-key-cell :snap="$snap" align="left" />
                                </div>
                            </div>
                            <div>
                                <div class="text-[10px] uppercase tracking-wider text-muted">Best raid</div>
                                <div class="font-mono text-xs">
                                    @php
                                        $best = null; $bestM = -1; $bestH = -1;
                                        foreach ((array) ($snap->raid_progression_json ?? []) as $p) {
                                            if (! is_array($p)) continue;
                                            $m = (int) ($p['mythic_bosses_killed'] ?? 0);
                                            $h = (int) ($p['heroic_bosses_killed'] ?? 0);
                                            if ($m > $bestM || ($m === $bestM && $h > $bestH)) {
                                                $bestM = $m; $bestH = $h;
                                                $best = is_string($p['summary'] ?? null) ? $p['summary'] : null;
                                            }
                                        }
                                    @endphp
                                    {{ $best ?? '-' }}
                                </div>
                            </div>
                        @elseif ($source === 'wowaudit')
                            <div>
                                <div class="text-[10px] uppercase tracking-wider text-muted">Highest key</div>
                                <div class="font-mono">{{ $snap->mplus_keystone !== null ? '+' . $snap->mplus_keystone : '-' }}</div>
                            </div>
                            <div class="col-span-2">
                                <div class="text-[10px] uppercase tracking-wider text-muted">Vault</div>
                                <div class="font-mono text-xs">
                                    @php
                                        $vault = (array) ($snap->vault_progress_json ?? []);
                                        $unlocked = 0;
                                        foreach (['raids', 'dungeons', 'world'] as $kind) {
                                            foreach (['option_1', 'option_2', 'option_3'] as $slot) {
                                                if (! empty($vault[$kind][$slot])) $unlocked++;
                                            }
                                        }
                                    @endphp
                                    {{ $unlocked }}/9 slots
                                </div>
                            </div>
                        @else
                            <div>
                                <div class="text-[10px] uppercase tracking-wider text-muted">Last online</div>
                                <div class="text-xs">{{ $snap->last_online_at?->diffForHumans() ?? '-' }}</div>
                            </div>
                        @endif
                    </div>
                @else
                    <p class="text-xs text-muted mt-2 italic">No data captured yet.</p>
                @endif
            </section>
        @endforeach
    </div>

    {{-- M+ activity: per-day heatmap + dungeon spread + recent runs.
         Sourced from member_mplus_runs (RIO 3-hourly sampling). --}}
    @include('dashboard.character._mplus-activity', ['mplusActivity' => $mplusActivity])

    {{-- BiS comparison: per-slot enchant + gem status against the
         class+spec SimulationCraft profile. Sources gear from Blizzard
         /character/equipment first, then RIO, then the most recent WCL
         parse - whichever has data. --}}
    @if ($bisComparison)
        @include('dashboard.character._bis-comparison', ['comparison' => $bisComparison])
    @else
        <section class="bg-panel border border-line rounded-lg p-4 mb-6">
            <h2 class="text-sm font-semibold uppercase tracking-wider mb-2">BiS comparison</h2>
            <p class="text-xs text-muted">
                @if ($bisGearSampleMissing)
                    No gear sample for this character yet. Blizzard, Raider.IO, and WCL all returned
                    empty. The character will appear here after the next sync that picks them up.
                @else
                    Gear sample available, but no SimulationCraft BiS profile is loaded for this
                    spec. SimC profiles only cover DPS and tank specs; healers need a manually
                    curated profile in <span class="font-mono">bis_profiles</span>.
                @endif
            </p>
        </section>
    @endif

    {{-- Bottom: parses + activity / actions / alts --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <section class="bg-panel border border-line rounded-lg overflow-hidden">
                <header class="px-4 py-3 border-b border-line flex items-center justify-between">
                    <h2 class="text-sm font-semibold uppercase tracking-wider">Recent WCL parses</h2>
                    <span class="text-xs text-muted">last {{ $recentParses->count() }}</span>
                </header>
                @if ($recentParses->isEmpty())
                    <div class="p-6 text-center text-muted text-sm">
                        No parses logged for this character yet. Run a WCL sync from
                        <a href="{{ route('admin.sync.index') }}" class="text-accent hover:underline">/admin/sync</a>.
                    </div>
                @else
                    <table class="w-full text-sm">
                        <thead class="text-xs text-muted uppercase tracking-wider">
                            <tr>
                                <th class="text-left px-4 py-2">When</th>
                                <th class="text-left px-2 py-2">Boss</th>
                                <th class="text-left px-2 py-2">Diff</th>
                                <th class="text-left px-2 py-2">Result</th>
                                <th class="text-right px-2 py-2">Per second</th>
                                <th class="text-right px-2 py-2">Parse</th>
                                <th class="text-right px-4 py-2">Report</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($recentParses as $p)
                                @php $f = $p->fight; @endphp
                                <tr class="border-t border-line">
                                    <td class="px-4 py-1.5 text-xs text-muted whitespace-nowrap">
                                        {{ $f?->start_time?->format('D d M H:i') ?? '-' }}
                                    </td>
                                    <td class="px-2 py-1.5">{{ $f?->name ?? '-' }}</td>
                                    <td class="px-2 py-1.5 text-xs text-muted">
                                        {{ \App\Models\WclFight::difficultyLabel($f?->difficulty) }}
                                    </td>
                                    <td class="px-2 py-1.5 text-xs">
                                        @if ($f?->kill)
                                            <span class="text-emerald-300">Kill</span>
                                        @else
                                            <span class="text-rose-300">{{ $f?->best_percentage !== null ? rtrim(rtrim(number_format((float) $f->best_percentage, 2), '0'), '.') . '%' : 'Wipe' }}</span>
                                        @endif
                                    </td>
                                    <td class="px-2 py-1.5 font-mono text-right">
                                        {{ $p->metric_per_second !== null ? number_format($p->metric_per_second, 0) : '-' }}
                                    </td>
                                    <td class="px-2 py-1.5 text-right">
                                        <x-parse-pill :percentile="$p->parse_percentile" />
                                    </td>
                                    <td class="px-4 py-1.5 text-right">
                                        @if ($f?->report)
                                            <a href="{{ route('reports.show', $f->report->code) }}"
                                               class="text-xs text-accent hover:underline">{{ \Illuminate\Support\Str::limit($f->report->title, 18) }}</a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>

            <section class="bg-panel border border-line rounded-lg overflow-hidden">
                <header class="px-4 py-3 border-b border-line">
                    <h2 class="text-sm font-semibold uppercase tracking-wider">Activity (GRM events)</h2>
                </header>
                @if ($recentEvents->isEmpty())
                    <div class="p-6 text-center text-muted text-sm">No events logged for this character yet.</div>
                @else
                    <ul class="divide-y divide-line">
                        @foreach ($recentEvents as $e)
                            @php
                                // payload_json is freeform per signal type. Surface a
                                // best-effort one-liner: prefer 'note' / 'message' / a
                                // 'from -> to' pair, else compact JSON.
                                $p = (array) ($e->payload_json ?? []);
                                $detail = $p['note']
                                    ?? $p['message']
                                    ?? (isset($p['from'], $p['to']) ? "{$p['from']} -> {$p['to']}" : null);
                                if ($detail === null && $p) {
                                    $detail = json_encode($p);
                                }
                            @endphp
                            <li class="px-4 py-2 text-sm flex items-center justify-between gap-3">
                                <span>
                                    <span class="text-xs uppercase tracking-wider text-muted mr-2">{{ str_replace('_', ' ', $e->type) }}</span>
                                    {{ $detail }}
                                </span>
                                <span class="text-xs text-muted whitespace-nowrap">
                                    {{ $e->occurred_at?->diffForHumans() ?? '-' }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>

        <div class="space-y-6">
            @if ($attendance)
                <section class="bg-panel border border-line rounded-lg p-4">
                    <h2 class="text-xs uppercase tracking-wider text-muted">Attendance</h2>
                    <div class="text-3xl font-mono mt-1">
                        {{ rtrim(rtrim(number_format((float) $attendance->attendance_pct, 1), '0'), '.') }}%
                    </div>
                    <p class="text-xs text-muted mt-1">
                        as of {{ $attendance->captured_at?->diffForHumans() ?? '-' }}
                    </p>
                </section>
            @endif

            <section class="bg-panel border border-line rounded-lg overflow-hidden">
                <header class="px-4 py-3 border-b border-line">
                    <h2 class="text-sm font-semibold uppercase tracking-wider">Team assignment</h2>
                </header>
                <div class="p-4 space-y-3">
                    <p class="text-xs text-muted">
                        Tick the team(s) this character actually plays in. Saving sets an
                        override that sticks across rank changes. Empty + Save (or Clear)
                        reverts to the rank-derived team.
                    </p>
                    <p class="text-xs text-muted">
                        Rank-derived team:
                        @if ($rankDerivedTeam)
                            <span class="text-ink">{{ \App\Models\TeamMapping::teamLabel($rankDerivedTeam) }}</span>
                        @else
                            <span class="italic">none</span>
                        @endif
                    </p>
                    <form method="POST" action="{{ route('character.teams.update', $member->name) }}" class="space-y-2">
                        @csrf
                        <div class="grid grid-cols-2 gap-1.5">
                            @foreach (\App\Models\TeamMapping::TEAMS as $team)
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="checkbox" name="teams[]" value="{{ $team }}"
                                           @checked(in_array($team, $teamValues, true))
                                           class="bg-bg border border-line rounded">
                                    <span>{{ \App\Models\TeamMapping::teamLabel($team) }}</span>
                                </label>
                            @endforeach
                        </div>
                        <div class="flex items-center gap-2 pt-2">
                            <button type="submit" name="action" value="save"
                                    class="px-3 py-1.5 rounded bg-accent text-white text-xs font-medium hover:opacity-90">
                                Save override
                            </button>
                            @if ($hasOverride)
                                <button type="submit" name="action" value="clear"
                                        class="px-3 py-1.5 rounded border border-line text-xs hover:bg-bg">
                                    Clear override
                                </button>
                            @endif
                        </div>
                    </form>
                </div>
            </section>

            <section class="bg-panel border border-line rounded-lg overflow-hidden">
                <header class="px-4 py-3 border-b border-line">
                    <h2 class="text-sm font-semibold uppercase tracking-wider">Alt cohort</h2>
                </header>
                @if ($altCohort->isEmpty())
                    <div class="p-4 text-xs text-muted italic">No linked alts.</div>
                @else
                    <ul class="divide-y divide-line">
                        @foreach ($altCohort as $alt)
                            @php $altCls = 'cls-' . strtoupper($alt->class ?? ''); @endphp
                            <li class="px-4 py-2 text-sm">
                                <a href="{{ route('character.show', $alt->name) }}" class="{{ $altCls }} hover:underline">{{ $alt->name }}</a>
                                @if ($alt->level)
                                    <span class="text-muted text-xs ml-1">L{{ $alt->level }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="bg-panel border border-line rounded-lg overflow-hidden">
                <header class="px-4 py-3 border-b border-line">
                    <h2 class="text-sm font-semibold uppercase tracking-wider">Action history</h2>
                </header>
                @if ($actionHistory->isEmpty())
                    <div class="p-4 text-xs text-muted italic">No actions recorded.</div>
                @else
                    <ul class="divide-y divide-line">
                        @foreach ($actionHistory as $a)
                            <li class="px-4 py-2 text-sm">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs uppercase tracking-wider">
                                        {{ str_replace('_', ' ', $a->action_type) }}
                                        <span class="text-muted">/</span>
                                        {{ $a->decision }}
                                    </span>
                                    <span class="text-xs text-muted">{{ $a->created_at?->diffForHumans() }}</span>
                                </div>
                                @if ($a->reviewedBy)
                                    <div class="text-xs text-muted mt-0.5">by {{ $a->reviewedBy->discord_username ?? '?' }}</div>
                                @endif
                                @if ($a->notes)
                                    <p class="text-xs text-ink mt-1">{{ $a->notes }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>
@endsection
