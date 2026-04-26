@extends('layouts.dashboard')

@section('title', 'Events')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="text-xl font-semibold">Events</h1>
    <div class="flex items-center gap-2">
        {{-- On-demand pull from Raid-Helper. Backfills events that
             pre-date the webhook setup, and recovers from any missed
             webhook deliveries. The scheduler also runs this once per
             day; this button is rate-limited to 1/hour per officer. --}}
        <form method="POST" action="{{ route('events.sync') }}">@csrf
            <button type="submit" title="Pull every event from Raid-Helper into the local cache. Rate-limited to once per hour. The daily scheduler runs this anyway, so manual triggers are rarely needed."
                    class="px-3 py-2 rounded border border-line text-sm text-muted hover:text-ink hover:border-muted">
                ⟳ Sync from Raid-Helper
            </button>
        </form>
        <a href="{{ route('events.create') }}" class="px-4 py-2 rounded bg-accent text-white text-sm font-medium hover:bg-accent/80">+ New event</a>
    </div>
</div>

@if (session('status'))
    <div class="mb-4 p-3 rounded bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-sm">{{ session('status') }}</div>
@endif

@if (isset($errors) && $errors->any())
    <div class="mb-4 p-3 rounded bg-rose-500/10 border border-rose-500/30 text-rose-300 text-sm">
        @foreach ($errors->all() as $err)<div>{{ $err }}</div>@endforeach
    </div>
@endif

<section class="bg-panel border border-line rounded-lg overflow-hidden">
    <header class="px-4 py-3 border-b border-line">
        <h2 class="text-sm font-semibold uppercase tracking-wider">Upcoming</h2>
    </header>
    @if ($upcoming->isEmpty())
        <div class="p-8 text-center text-muted text-sm">No upcoming events. <a href="{{ route('events.create') }}" class="text-accent hover:underline">Create one</a>.</div>
    @else
        <ul class="divide-y divide-line">
            @foreach ($upcoming as $event)
                <li class="px-4 py-3 flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <a href="{{ route('events.show', $event) }}" class="text-ink hover:text-accent">{{ $event->title }}</a>
                        <div class="text-xs text-muted mt-1">
                            {{ $event->starts_at->setTimezone(config('raidhelper.timezone'))->format('D d M Y, H:i T') }}
                            <span class="text-line">|</span>
                            {{ $event->signups()->count() }} signed up
                        </div>
                    </div>
                    <a href="{{ $event->discordJumpUrl() }}" class="text-xs text-accent hover:underline whitespace-nowrap" target="_blank" rel="noopener">View on Discord →</a>
                </li>
            @endforeach
        </ul>
    @endif
</section>

@if ($past->isNotEmpty())
<section class="mt-6 bg-panel border border-line rounded-lg overflow-hidden">
    <header class="px-4 py-3 border-b border-line">
        <h2 class="text-sm font-semibold uppercase tracking-wider text-muted">Past (last 20)</h2>
    </header>
    <ul class="divide-y divide-line">
        @foreach ($past as $event)
            <li class="px-4 py-2 flex items-center justify-between gap-3 text-sm text-muted">
                <a href="{{ route('events.show', $event) }}" class="hover:text-ink">{{ $event->title }}</a>
                <span class="text-xs">{{ $event->starts_at->setTimezone(config('raidhelper.timezone'))->format('d M Y') }}</span>
            </li>
        @endforeach
    </ul>
</section>
@endif
@endsection
