@extends('layouts.dashboard')

@section('title', 'Google Calendar')

@section('content')
    <div class="flex items-start justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-semibold">Google Calendar</h1>
            <p class="text-sm text-muted mt-1">
                One officer authorises here, the dashboard creates a dedicated calendar
                ("Regenesis Officers" by default), and pushes raid events to it as
                they're created, edited or deleted. Other officers add the calendar to
                their Google account via Google's native sharing UI.
            </p>
            <p class="text-sm text-muted mt-1">
                The dashboard is the source of truth: this is a one-way push, mirroring
                the .ics feed. Editing events on the Google side won't change anything
                in the dashboard, and the daily reconcile job will overwrite Google-side
                edits the next time it runs.
            </p>
        </div>
    </div>

    @if (session('status'))
        <div class="mb-4 p-3 rounded border border-green-700 bg-green-900/30 text-sm text-green-200">
            {{ session('status') }}
        </div>
    @endif
    @if ($errors->any())
        <div class="mb-4 p-3 rounded border border-red-700 bg-red-900/30 text-sm text-red-200">
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (! $isConfigured)
        <section class="bg-panel border border-line rounded-lg overflow-hidden mb-6">
            <header class="px-5 py-3 border-b border-line">
                <h2 class="font-semibold">OAuth not configured</h2>
            </header>
            <div class="p-5 text-sm text-muted space-y-2">
                <p>
                    Set <code class="text-ink">GOOGLE_CALENDAR_CLIENT_ID</code>,
                    <code class="text-ink">GOOGLE_CALENDAR_CLIENT_SECRET</code> and
                    <code class="text-ink">GOOGLE_CALENDAR_REDIRECT_URI</code> in
                    <code class="text-ink">.env</code>, then reload this page.
                </p>
                <p>
                    Setup steps live in the README under section "Google Calendar
                    (officer push)". You'll need a Google Cloud project with the
                    Google Calendar API enabled and an OAuth client (type: Web
                    application) whose redirect URI matches the env value above.
                </p>
            </div>
        </section>
    @elseif ($connector === null)
        <section class="bg-panel border border-line rounded-lg overflow-hidden mb-6">
            <header class="px-5 py-3 border-b border-line">
                <h2 class="font-semibold">Not connected</h2>
            </header>
            <div class="p-5 text-sm text-muted space-y-3">
                <p>No officer has connected a Google account yet. Click below to start the OAuth handshake.</p>
                <p class="text-xs">
                    On clicking Connect you'll go to Google's consent screen and grant
                    calendar + email access. The dashboard will then create a dedicated
                    "Regenesis Officers" calendar on your account and queue every event
                    in the next 90 days for initial sync.
                </p>
                <a href="{{ route('auth.google-calendar.start') }}"
                   class="inline-block text-sm px-4 py-2 rounded bg-accent text-white">
                    Connect to Google
                </a>
            </div>
        </section>
    @else
        <section class="bg-panel border border-line rounded-lg overflow-hidden mb-6">
            <header class="px-5 py-3 border-b border-line flex items-center justify-between">
                <h2 class="font-semibold">Connected</h2>
                <span class="text-xs px-2 py-0.5 rounded border border-emerald-700/50 text-emerald-300">Active</span>
            </header>
            <div class="p-5 text-sm space-y-2">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <div class="text-xs uppercase tracking-wider text-muted">Connecting officer</div>
                        <div class="text-ink">{{ $connector->name }} ({{ $connector->discord_username ?? 'no discord username' }})</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-wider text-muted">Google account</div>
                        <div class="text-ink">{{ $connector->google_email ?? 'unknown' }}</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-wider text-muted">Calendar id</div>
                        <div class="text-ink font-mono text-xs break-all">{{ $connector->google_calendar_id ?? '(not set)' }}</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-wider text-muted">Connected at</div>
                        <div class="text-ink">{{ $connector->google_calendar_connected_at?->diffForHumans() ?? 'unknown' }}</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-wider text-muted">Tracked events</div>
                        <div class="text-ink">{{ $eventsTracked }} of {{ $eventCountInWindow }} in feed window</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-wider text-muted">Last sync</div>
                        <div class="text-ink">
                            @if ($state)
                                <span class="uppercase tracking-wider text-xs
                                    @if ($state['status'] === 'failed') text-rose-300
                                    @elseif ($state['status'] === 'done') text-emerald-300
                                    @else text-amber-300
                                    @endif">{{ $state['status'] }}</span>
                                @if (! empty($state['finished_at']))
                                    <span class="text-muted text-xs ml-1">
                                        {{ \Illuminate\Support\Carbon::parse($state['finished_at'])->diffForHumans() }}
                                    </span>
                                @endif
                                @if (! empty($state['error']))
                                    <div class="text-xs text-rose-300 mt-1">{{ $state['error'] }}</div>
                                @endif
                            @else
                                <span class="text-muted">never</span>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="pt-4 flex items-center gap-2">
                    <form method="POST" action="{{ route('admin.google-calendar.test') }}">
                        @csrf
                        <button type="submit"
                                class="text-sm px-3 py-2 rounded border border-line bg-bg hover:bg-panel">
                            Test connection
                        </button>
                    </form>
                    @if ($isConnectedAsMe)
                        <form method="POST" action="{{ route('auth.google-calendar.disconnect') }}"
                              onsubmit="return confirm('Disconnect Google Calendar? The calendar itself will stay on your Google account, but the dashboard will stop pushing to it.');">
                            @csrf
                            <button type="submit"
                                    class="text-sm px-3 py-2 rounded border border-rose-700/50 text-rose-300 hover:bg-rose-950/30">
                                Disconnect
                            </button>
                        </form>
                    @else
                        <p class="text-xs text-muted">
                            Only {{ $connector->name }} can disconnect (the connection lives on their user row).
                        </p>
                    @endif
                </div>
            </div>
        </section>
    @endif

    <section class="bg-panel border border-line rounded-lg overflow-hidden">
        <header class="px-5 py-3 border-b border-line">
            <h2 class="font-semibold">Sharing the calendar with other officers</h2>
        </header>
        <div class="p-5 text-sm text-muted space-y-2">
            <ol class="list-decimal list-inside space-y-1">
                <li>Open <a href="https://calendar.google.com" target="_blank" rel="noopener" class="text-accent">Google Calendar</a> on the connected officer's account.</li>
                <li>Find "Regenesis Officers" in the My calendars list, hover, click the three dots, then "Settings and sharing".</li>
                <li>Under "Share with specific people or groups", add each officer's Google address. Permission: "See all event details" is enough for read-only viewing.</li>
                <li>Officers will receive an email invite. Once accepted, the calendar appears alongside their personal calendars.</li>
            </ol>
            <p class="text-xs">
                Don't share the calendar publicly unless you intend the entire schedule to be world-readable. The same data is already available via the .ics feed for anyone with the link.
            </p>
        </div>
    </section>
@endsection
