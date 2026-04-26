<section class="bg-panel border border-line rounded-lg overflow-hidden">
    <header class="px-4 py-3 border-b border-line flex items-center justify-between">
        <h2 class="text-sm font-semibold uppercase tracking-wider">Upcoming events</h2>
        <a href="{{ route('events.create') }}" class="text-xs text-accent hover:underline">+ Create</a>
    </header>
    @if ($upcomingEvents->isEmpty())
        <div class="p-8 text-center text-muted text-sm">
            No upcoming events. <a href="{{ route('events.create') }}" class="text-accent hover:underline">Create one</a>.
        </div>
    @else
        <ul class="divide-y divide-line">
            @foreach ($upcomingEvents as $event)
                <li class="px-4 py-3 flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <a href="{{ route('events.show', $event) }}" class="text-sm hover:text-accent">{{ $event->title }}</a>
                        <div class="text-xs text-muted">
                            {{ $event->starts_at->setTimezone(config('raidhelper.timezone'))->format('D d M H:i T') }}
                            <span class="text-line">|</span>
                            {{ $event->signups_count }} signed up
                        </div>
                    </div>
                    <a href="{{ $event->discordJumpUrl() }}" target="_blank" rel="noopener"
                       class="text-xs text-muted hover:text-accent whitespace-nowrap">→ Discord</a>
                </li>
            @endforeach
        </ul>
    @endif
</section>
