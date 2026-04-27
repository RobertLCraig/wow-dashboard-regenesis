@extends('layouts.dashboard')

@section('title', 'Social')

@section('content')
    <div class="flex items-baseline justify-between mb-6 flex-wrap gap-2">
        <div>
            <h1 class="text-2xl font-semibold">Social</h1>
            <p class="text-sm text-muted mt-1">
                Guild events, world events, and what's coming up over the next {{ $windowDays }} days.
            </p>
        </div>
        <div class="flex items-center gap-3 text-xs text-muted">
            <span>{{ $totalEvents }} {{ \Illuminate\Support\Str::plural('event', $totalEvents) }}</span>
            {{-- View toggle: list (default) shows chronological week
                 groups; grid shows a month-style calendar where each
                 day cell lists everything overlapping it. --}}
            <span class="inline-flex rounded border border-line overflow-hidden">
                <a href="{{ route('dashboard.social') }}"
                   class="px-2 py-1 transition {{ $view === 'list' ? 'bg-accent/15 text-ink' : 'hover:text-ink' }}">List</a>
                <a href="{{ route('dashboard.social', ['view' => 'grid']) }}"
                   class="px-2 py-1 transition border-l border-line {{ $view === 'grid' ? 'bg-accent/15 text-ink' : 'hover:text-ink' }}">Calendar</a>
            </span>
            @if (! empty($subscribeUrl))
                <a href="{{ $subscribeUrl }}"
                   class="inline-flex items-center gap-1 px-2 py-1 rounded border border-line hover:border-accent hover:text-ink transition"
                   title="Per-user feed: raid events + world events. Copy the link or paste into Google Calendar / Apple Calendar / Outlook.">
                    Subscribe (.ics)
                </a>
            @endif
            <a href="{{ route('calendar.world') }}"
               class="inline-flex items-center gap-1 px-2 py-1 rounded border border-line hover:border-accent hover:text-ink transition"
               title="World events only (Darkmoon Faire, holidays, Trading Post). Public feed - share with anyone, no auth.">
                World events
            </a>
        </div>
    </div>

    @if ($quickCreatePreset)
        {{-- Same shared widget the team dashboards use; the social
             preset posts to #social-events with the accept/maybe/decline
             template and no fixed-day pills. --}}
        <div class="mb-6">
            @include('dashboard.widgets.quick-create', ['preset' => $quickCreatePreset, 'teamSlug' => 'social'])
        </div>
    @endif

    @if ($announcements->isNotEmpty())
        <section class="mb-8">
            <header class="flex items-baseline justify-between mb-2">
                <h2 class="text-sm font-semibold uppercase tracking-wider">Latest from Discord</h2>
                <span class="text-[10px] text-muted">last {{ $announcementWindowDays }} days</span>
            </header>
            <ul class="space-y-2">
                @foreach ($announcements as $a)
                    <li class="bg-panel border border-line rounded-lg p-3">
                        <div class="flex items-baseline justify-between gap-2 flex-wrap">
                            <span class="text-sm font-medium">{{ $a->author_username }}</span>
                            <a href="{{ $a->discordUrl() }}" target="_blank" rel="noopener"
                               class="text-[10px] text-muted hover:text-accent">
                                {{ $a->posted_at?->diffForHumans() }} - open in Discord &rarr;
                            </a>
                        </div>
                        <p class="text-sm text-ink/90 mt-1 whitespace-pre-line">{{ \Illuminate\Support\Str::limit($a->content, 400) }}</p>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($view === 'grid')
        @if (empty($days))
            <div class="bg-panel border border-line rounded-lg p-8 text-center text-muted">
                Nothing to show in calendar view.
            </div>
        @else
            @php
                $eventToneClasses = [
                    'sky'    => 'bg-sky-900/40 text-sky-200 border-sky-800/60',
                    'violet' => 'bg-violet-900/40 text-violet-200 border-violet-800/60',
                    'amber'  => 'bg-amber-900/40 text-amber-200 border-amber-800/60',
                ];
            @endphp
            <div class="bg-panel border border-line rounded-lg overflow-hidden">
                <div class="grid grid-cols-7 text-xs uppercase tracking-wider text-muted border-b border-line">
                    @foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $dow)
                        <div class="px-2 py-2 text-center">{{ $dow }}</div>
                    @endforeach
                </div>
                <div class="grid grid-cols-7 gap-px bg-line">
                    @foreach ($days as $day)
                        @php
                            $cellTone = ! $day['in_window']
                                ? 'bg-bg/50 text-muted/60'
                                : ($day['is_today'] ? 'bg-accent/10' : 'bg-panel');
                            $isMonthStart = $day['date']->day === 1;
                        @endphp
                        <div class="{{ $cellTone }} min-h-[88px] p-1.5 flex flex-col gap-1">
                            <div class="flex items-center justify-between">
                                <span class="text-xs {{ $day['is_today'] ? 'font-semibold text-ink' : 'text-muted' }}">
                                    {{ $day['date']->format('j') }}
                                </span>
                                @if ($isMonthStart)
                                    <span class="text-[10px] uppercase text-muted">{{ $day['date']->format('M') }}</span>
                                @endif
                            </div>
                            @foreach (array_slice($day['events'], 0, 3) as $event)
                                @php $tone = $eventToneClasses[$event['tone']] ?? 'bg-line/40 text-muted border-line'; @endphp
                                <div class="text-[10px] leading-tight px-1.5 py-0.5 rounded border {{ $tone }} truncate"
                                     title="{{ $event['name'] }} - {{ $event['starts_at']->format('D j M H:i') }}">
                                    {{ $event['name'] }}
                                </div>
                            @endforeach
                            @if (count($day['events']) > 3)
                                <div class="text-[10px] text-muted">+{{ count($day['events']) - 3 }} more</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @elseif (empty($eventsByWeek))
        <div class="bg-panel border border-line rounded-lg p-8 text-center text-muted">
            Nothing scheduled in the next {{ $windowDays }} days.
            New Raid-Helper events appear here as they're created in Discord.
        </div>
    @else
        <div class="space-y-6">
            @foreach ($eventsByWeek as $weekKey => $week)
                @php
                    $weekStart = $week['week_start'];
                    $weekEnd = $weekStart->copy()->addDays(6);
                    $now = \Carbon\CarbonImmutable::now();
                    $thisWeekKey = $now->format('o-W');
                    $nextWeekKey = $now->addWeek()->format('o-W');
                    $heading = match ($weekKey) {
                        $thisWeekKey => 'This week',
                        $nextWeekKey => 'Next week',
                        default      => $weekStart->format('M j') . ' - ' . $weekEnd->format('M j'),
                    };
                @endphp
                <section>
                    <header class="flex items-baseline justify-between mb-2">
                        <h2 class="text-sm font-semibold uppercase tracking-wider">{{ $heading }}</h2>
                        <span class="text-[10px] text-muted">{{ $weekStart->format('o') }} W{{ $weekStart->format('W') }}</span>
                    </header>

                    <ul class="space-y-2">
                        @foreach ($week['events'] as $event)
                            @php
                                $tone = match ($event['tone']) {
                                    'sky'    => 'border-sky-700/40 bg-sky-950/30',
                                    'violet' => 'border-violet-700/40 bg-violet-950/30',
                                    default  => 'border-line bg-panel',
                                };
                                $kindBadge = match ($event['kind']) {
                                    'guild' => ['Guild', 'bg-sky-900/40 text-sky-300 border-sky-800/60'],
                                    'world' => ['World', 'bg-violet-900/40 text-violet-300 border-violet-800/60'],
                                    default => ['Event', 'bg-line/40 text-muted border-line'],
                                };
                            @endphp
                            <li class="border rounded-lg p-3 flex items-start gap-3 {{ $tone }}">
                                <div class="flex-shrink-0 text-center w-14">
                                    <div class="text-[10px] uppercase text-muted tracking-wider">
                                        {{ $event['starts_at']->format('D') }}
                                    </div>
                                    <div class="text-lg font-semibold leading-none mt-0.5">
                                        {{ $event['starts_at']->format('j') }}
                                    </div>
                                    <div class="text-[10px] text-muted">{{ $event['starts_at']->format('M') }}</div>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <h3 class="font-medium text-ink">{{ $event['name'] }}</h3>
                                        <span class="text-[10px] uppercase tracking-wider px-1.5 py-0.5 rounded border {{ $kindBadge[1] }}">
                                            {{ $kindBadge[0] }}
                                        </span>
                                    </div>
                                    <div class="text-xs text-muted mt-0.5">
                                        @if ($event['ends_at'] && ! $event['starts_at']->isSameDay($event['ends_at']))
                                            {{ $event['starts_at']->format('D j M') }}
                                            <span class="text-line">to</span>
                                            {{ $event['ends_at']->format('D j M') }}
                                        @else
                                            {{ $event['starts_at']->format('D j M H:i') }}
                                        @endif
                                        <span class="text-line mx-1">|</span>
                                        {{ $event['starts_at']->diffForHumans() }}
                                    </div>
                                    @if (! empty($event['description']))
                                        <p class="text-xs text-muted mt-1 line-clamp-2">{{ $event['description'] }}</p>
                                    @endif
                                </div>
                                <div class="flex flex-col items-end gap-1 text-xs">
                                    @if (! empty($event['event_url']))
                                        <a href="{{ $event['event_url'] }}" class="text-accent hover:underline">Details &rarr;</a>
                                    @endif
                                    @if (! empty($event['discord_url']))
                                        <a href="{{ $event['discord_url'] }}" target="_blank" rel="noopener" class="text-muted hover:text-accent">Discord &rarr;</a>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    @endif
@endsection
