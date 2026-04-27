@extends('layouts.dashboard')

@section('title', 'Social')

@section('content')
    @php
        $channelId = $quickCreatePreset['channel_id'] ?? collect(config('raidhelper.channels', []))->firstWhere('name', 'social-events')['id'] ?? null;
        $channelName = collect(config('raidhelper.channels', []))->firstWhere('id', $channelId)['name'] ?? 'social-events';
    @endphp

    <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
        <h1 class="text-xl font-semibold">Social</h1>
        <div class="flex items-center gap-3 flex-wrap">
            @if (! empty($subscribeUrl))
                <a href="{{ $subscribeUrl }}"
                   class="text-xs px-3 py-1 rounded border border-line bg-bg hover:bg-panel"
                   title="Per-user feed: raid events + world events. Paste into Google Calendar / Apple Calendar / Outlook.">
                    Subscribe (.ics) &rarr;
                </a>
            @endif
            <a href="{{ route('calendar.world') }}"
               class="text-xs px-3 py-1 rounded border border-line bg-bg hover:bg-panel"
               title="World events only (Darkmoon Faire, holidays, Trading Post). Public feed - share with anyone, no auth.">
                World events feed &rarr;
            </a>
            <span class="text-xs text-muted">
                Channel: <code class="text-ink">#{{ $channelName }}</code>
                <span class="text-line">|</span>
                {{ $totalEvents }} {{ \Illuminate\Support\Str::plural('event', $totalEvents) }} in next {{ $windowDays }} days
            </span>
        </div>
    </div>

    <div class="grid grid-cols-1 {{ $quickCreatePreset ? 'lg:grid-cols-3' : '' }} gap-6">
        <div class="{{ $quickCreatePreset ? 'lg:col-span-2' : '' }} space-y-6">
            @if ($announcements->isNotEmpty())
                <section class="bg-panel border border-line rounded-lg overflow-hidden">
                    <header class="px-4 py-3 border-b border-line flex items-center justify-between">
                        <h2 class="text-sm font-semibold uppercase tracking-wider">Latest from Discord</h2>
                        <span class="text-[10px] text-muted">last {{ $announcementWindowDays }} days</span>
                    </header>
                    <ul class="divide-y divide-line">
                        @foreach ($announcements as $a)
                            <li class="px-4 py-3">
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

            <section class="bg-panel border border-line rounded-lg overflow-hidden">
                <header class="px-4 py-3 border-b border-line flex items-center justify-between flex-wrap gap-2">
                    <h2 class="text-sm font-semibold uppercase tracking-wider">Events</h2>
                    {{-- View toggle: list (default) groups chronologically by week;
                         grid renders a month-style calendar where each cell shows
                         everything overlapping that day. --}}
                    <span class="inline-flex rounded border border-line overflow-hidden text-xs">
                        <a href="{{ route('dashboard.social') }}"
                           class="px-2.5 py-1 transition {{ $view === 'list' ? 'bg-accent/15 text-ink' : 'text-muted hover:text-ink' }}">List</a>
                        <a href="{{ route('dashboard.social', ['view' => 'grid']) }}"
                           class="px-2.5 py-1 transition border-l border-line {{ $view === 'grid' ? 'bg-accent/15 text-ink' : 'text-muted hover:text-ink' }}">Calendar</a>
                    </span>
                </header>

                @if ($view === 'grid')
                    @if (empty($days))
                        <div class="p-8 text-center text-muted text-sm">Nothing to show in calendar view.</div>
                    @else
                        @php
                            $eventToneClasses = [
                                'sky'    => 'bg-sky-900/40 text-sky-200 border-sky-800/60',
                                'violet' => 'bg-violet-900/40 text-violet-200 border-violet-800/60',
                                'amber'  => 'bg-amber-900/40 text-amber-200 border-amber-800/60',
                            ];
                        @endphp
                        <div class="grid grid-cols-7 text-xs uppercase tracking-wider text-muted border-b border-line bg-bg/30">
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
                    @endif
                @elseif (empty($eventsByWeek))
                    <div class="p-8 text-center text-muted text-sm">
                        Nothing scheduled in the next {{ $windowDays }} days.
                        New Raid-Helper events appear here as they're created in Discord.
                    </div>
                @else
                    <div class="divide-y divide-line">
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
                            <div class="px-4 py-3">
                                <div class="flex items-baseline justify-between mb-2">
                                    <h3 class="text-xs uppercase tracking-wider text-muted">{{ $heading }}</h3>
                                    <span class="text-[10px] text-muted">{{ $weekStart->format('o') }} W{{ $weekStart->format('W') }}</span>
                                </div>
                                <ul class="space-y-2">
                                    @foreach ($week['events'] as $event)
                                        @php
                                            $tone = match ($event['tone']) {
                                                'sky'    => 'border-sky-700/40 bg-sky-950/30',
                                                'violet' => 'border-violet-700/40 bg-violet-950/30',
                                                'amber'  => 'border-amber-700/40 bg-amber-950/30',
                                                default  => 'border-line bg-bg/40',
                                            };
                                            $kindBadge = match ($event['kind']) {
                                                'guild' => ['Guild', 'bg-sky-900/40 text-sky-300 border-sky-800/60'],
                                                'world' => ['World', 'bg-violet-900/40 text-violet-300 border-violet-800/60'],
                                                default => ['Event', 'bg-line/40 text-muted border-line'],
                                            };
                                        @endphp
                                        <li class="border rounded-lg p-3 flex items-start gap-3 {{ $tone }}">
                                            <div class="flex-shrink-0 text-center w-12">
                                                <div class="text-[10px] uppercase text-muted tracking-wider">
                                                    {{ $event['starts_at']->format('D') }}
                                                </div>
                                                <div class="text-base font-semibold leading-none mt-0.5">
                                                    {{ $event['starts_at']->format('j') }}
                                                </div>
                                                <div class="text-[10px] text-muted">{{ $event['starts_at']->format('M') }}</div>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-center gap-2 flex-wrap">
                                                    <h4 class="text-sm font-medium text-ink">{{ $event['name'] }}</h4>
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
                                            <div class="flex flex-col items-end gap-1 text-xs whitespace-nowrap">
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
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
        </div>

        @if ($quickCreatePreset)
            <div class="space-y-6">
                @include('dashboard.widgets.quick-create', ['preset' => $quickCreatePreset, 'teamSlug' => 'social'])
            </div>
        @endif
    </div>
@endsection
