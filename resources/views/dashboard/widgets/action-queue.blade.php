@php
    $tabs = [
        'promote' => ['label' => 'Promote', 'tone' => 'emerald', 'list' => $actionQueue['promote']],
        'demote'  => ['label' => 'Demote',  'tone' => 'rose',    'list' => $actionQueue['demote']],
        'kick'    => ['label' => 'Kick',    'tone' => 'orange',  'list' => $actionQueue['kick']],
    ];
    $totalQueued = collect($actionQueue)->sum(fn ($l) => $l->count());
@endphp
<section class="bg-panel border border-line rounded-lg overflow-hidden" x-data="{ tab: 'promote' }">
    <header class="px-4 py-3 border-b border-line flex items-center justify-between">
        <h2 class="text-sm font-semibold uppercase tracking-wider">Action queue</h2>
        <span class="text-xs text-muted">{{ $totalQueued }} waiting review</span>
    </header>
    <div class="border-b border-line flex text-sm">
        @foreach ($tabs as $key => $tab)
            <button type="button"
                    @click="tab = '{{ $key }}'"
                    :class="tab === '{{ $key }}' ? 'border-{{ $tab['tone'] }}-400 text-ink' : 'border-transparent text-muted hover:text-ink'"
                    class="px-4 py-2 border-b-2 -mb-px transition-colors">
                {{ $tab['label'] }}
                <span class="ml-1 text-xs text-muted">({{ $tab['list']->count() }})</span>
            </button>
        @endforeach
    </div>

    @if (session('status'))
        <div class="px-4 py-2 text-xs text-emerald-300 border-b border-line">{{ session('status') }}</div>
    @endif

    @foreach ($tabs as $key => $tab)
        <div x-show="tab === '{{ $key }}'" x-cloak>
            @if ($tab['list']->isEmpty())
                <div class="p-8 text-center text-muted text-sm">No members flagged for {{ strtolower($tab['label']) }}.</div>
            @else
                <ul class="divide-y divide-line">
                    @foreach ($tab['list'] as $m)
                        @php $cls = 'cls-' . strtoupper($m->class ?? ''); @endphp
                        <li class="px-4 py-3 flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <div class="text-sm">
                                    <span class="{{ $cls }} font-medium">{{ $m->name }}</span>
                                    <span class="text-muted text-xs ml-1">L{{ $m->level }} {{ $m->rank_name }}</span>
                                </div>
                                <div class="text-xs text-muted">last seen {{ $m->last_online_at?->diffForHumans() ?? 'never' }}</div>
                            </div>
                            <div class="flex items-center gap-1 text-xs">
                                <form method="POST" action="{{ route('dashboard.member.actions.store', $m) }}">@csrf
                                    <input type="hidden" name="action_type" value="{{ $key }}">
                                    <input type="hidden" name="decision" value="accepted">
                                    <button class="px-2 py-1 rounded bg-emerald-500/20 text-emerald-300 hover:bg-emerald-500/30">Accept</button>
                                </form>
                                <form method="POST" action="{{ route('dashboard.member.actions.store', $m) }}">@csrf
                                    <input type="hidden" name="action_type" value="{{ $key }}">
                                    <input type="hidden" name="decision" value="dismissed">
                                    <button class="px-2 py-1 rounded bg-line text-muted hover:bg-line/70 hover:text-ink">Dismiss</button>
                                </form>
                                <form method="POST" action="{{ route('dashboard.member.actions.store', $m) }}">@csrf
                                    <input type="hidden" name="action_type" value="{{ $key }}">
                                    <input type="hidden" name="decision" value="snoozed">
                                    <input type="hidden" name="snooze_days" value="7">
                                    <button class="px-2 py-1 rounded bg-amber-500/20 text-amber-300 hover:bg-amber-500/30" title="Hide for 7 days">Snooze 7d</button>
                                </form>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endforeach
</section>
