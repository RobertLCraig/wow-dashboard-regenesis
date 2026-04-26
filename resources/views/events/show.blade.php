@extends('layouts.dashboard')

@section('title', $event->title)

@section('content')
<div class="max-w-3xl mx-auto">
    @if (session('status'))
        <div class="mb-4 p-3 rounded bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-sm">{{ session('status') }}</div>
    @endif

    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold">{{ $event->title }}</h1>
        <a href="{{ route('events.index') }}" class="text-sm text-muted hover:text-ink">&larr; All events</a>
    </div>

    <section class="bg-panel border border-line rounded-lg p-6 mb-6">
        <div class="text-sm text-muted">
            {{ $event->starts_at->setTimezone(config('raidhelper.timezone'))->format('l d F Y, H:i T') }}
            @if ($event->ends_at)
                &mdash; {{ $event->ends_at->setTimezone(config('raidhelper.timezone'))->format('H:i T') }}
            @endif
        </div>
        <div class="text-xs text-muted mt-1">Channel {{ $event->channel_id }} | Template {{ $event->template_id }}</div>

        @if ($event->description)
            <div class="mt-4 text-sm whitespace-pre-wrap">{{ $event->description }}</div>
        @endif
    </section>

    <section class="bg-panel border border-line rounded-lg p-6 mb-6">
        <h2 class="text-sm font-semibold uppercase tracking-wider mb-3">Calendar</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <a href="{{ $jumpUrl }}" target="_blank" rel="noopener"
               class="px-3 py-2 rounded bg-line text-center text-sm hover:bg-line/70">
                View on Discord
            </a>
            <a href="{{ $icsUrl }}"
               class="px-3 py-2 rounded bg-accent/20 text-accent text-center text-sm hover:bg-accent/30">
                Download .ics
            </a>
            <button type="button" onclick="navigator.clipboard.writeText('{{ $webcalUrl }}'); this.innerText='Copied!'"
                    class="px-3 py-2 rounded bg-line text-center text-sm hover:bg-line/70">
                Copy webcal:// feed
            </button>
        </div>
        <p class="text-xs text-muted mt-2">
            Add the webcal:// link to Google Calendar (Other calendars -> From URL) for live updates of every event you create here.
        </p>
    </section>

    <section class="bg-panel border border-line rounded-lg overflow-hidden mb-6">
        <header class="px-4 py-3 border-b border-line flex items-center justify-between">
            <h2 class="text-sm font-semibold uppercase tracking-wider">Sign-ups</h2>
            <span class="text-xs text-muted">{{ $event->signups()->count() }}</span>
        </header>
        @if ($event->signups->isEmpty())
            <div class="p-8 text-center text-muted text-sm">No sign-ups yet.</div>
        @else
            <ul class="divide-y divide-line">
                @foreach ($event->signups()->orderBy('position')->get() as $s)
                    @php $cls = 'cls-' . strtoupper($s->class_name ?? ''); @endphp
                    <li class="px-4 py-2 text-sm flex items-center justify-between">
                        <span>
                            <span class="{{ $cls }}">{{ $s->name }}</span>
                            @if ($s->spec_name)
                                <span class="text-muted text-xs ml-1">{{ $s->spec_name }}</span>
                            @endif
                        </span>
                        <span class="text-xs text-muted">{{ $s->status }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <form method="POST" action="{{ route('events.destroy', $event) }}"
          onsubmit="return confirm('Delete this event from Discord and the dashboard? This is permanent.')">
        @csrf
        @method('DELETE')
        <button type="submit" class="text-sm text-rose-400 hover:text-rose-300">Delete event</button>
    </form>
</div>
@endsection
