@php
    $typeColours = [
        'PROMOTED' => 'bg-emerald-500/20 text-emerald-300',
        'DEMOTED' => 'bg-rose-500/20 text-rose-300',
        'JOINED' => 'bg-blue-500/20 text-blue-300',
        'LEFT' => 'bg-slate-500/20 text-slate-300',
        'KICKED' => 'bg-orange-500/20 text-orange-300',
        'BANNED' => 'bg-red-500/20 text-red-300',
        'CAME_ONLINE' => 'bg-cyan-500/20 text-cyan-300',
        'PUBLIC_NOTE' => 'bg-violet-500/20 text-violet-300',
        'OFFICER_NOTE' => 'bg-purple-500/20 text-purple-300',
        'LEVEL_UP' => 'bg-yellow-500/20 text-yellow-300',
        'NAME_CHANGE' => 'bg-pink-500/20 text-pink-300',
        'INACTIVE_RETURN' => 'bg-teal-500/20 text-teal-300',
        'EVENT_BIRTHDAY' => 'bg-fuchsia-500/20 text-fuchsia-300',
        'EVENT_ANNIVERSARY' => 'bg-amber-500/20 text-amber-300',
    ];
@endphp
<section class="bg-panel border border-line rounded-lg overflow-hidden">
    <div x-data="{ explain: false }">
        <header class="px-4 py-3 border-b border-line flex items-center justify-between">
            <h2 class="text-sm font-semibold uppercase tracking-wider flex items-center gap-2">
                <span>Recent activity</span>
                <x-explainer-toggle />
            </h2>
            <span class="text-xs text-muted">{{ count($timeline) }} events</span>
        </header>
        <x-explainer-panel title="Recent activity">
            Time-ordered guild log: promotions, demotions, joins, leaves, kicks, bans,
            level ups, name changes, returns from inactivity, anniversaries, officer
            notes. Pulled from GRM SavedVariables on each sync, so it's only as fresh as
            the last upload. Skim it at the start of an officer session to catch up on
            what's happened between log-ins without having to scroll Discord.
        </x-explainer-panel>
    </div>
    @if ($timeline->isEmpty())
        <div class="p-8 text-center text-muted text-sm">No log entries yet.</div>
    @else
        <ol class="divide-y divide-line">
            @foreach ($timeline as $log)
                @php
                    $type = $log->type_name ?? 'UNKNOWN';
                    $tone = $typeColours[$type] ?? 'bg-line text-muted';
                @endphp
                <li class="px-4 py-2 text-sm flex items-start gap-3">
                    <span class="text-xs uppercase px-2 py-0.5 rounded {{ $tone }} whitespace-nowrap">{{ $type }}</span>
                    <div class="flex-1 min-w-0">
                        <div class="text-ink truncate">{{ $log->plainMessage() }}</div>
                        <div class="text-xs text-muted">{{ $log->occurred_at->diffForHumans() }}</div>
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</section>
