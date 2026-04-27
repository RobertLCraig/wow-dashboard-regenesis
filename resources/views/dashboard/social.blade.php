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

    @if (empty($eventsByWeek))
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
