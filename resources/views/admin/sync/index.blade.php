@extends('layouts.dashboard')

@section('title', 'Data sync')

@push('head')
    @if ($autoRefresh)
        {{-- Cheap polling: meta-refresh while at least one source is
             mid-sync. Stops as soon as everything is done/failed/idle. --}}
        <meta http-equiv="refresh" content="5">
    @endif
@endpush

@section('content')
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">Data sync</h1>
            <p class="text-sm text-muted mt-1">
                One panel per data source. Background syncs return immediately;
                this page auto-refreshes while one is in progress.
            </p>
        </div>
        <a href="{{ route('dashboard') }}" class="text-sm text-accent hover:underline">Back to dashboard</a>
    </div>

    @if (session('status'))
        <div class="mt-4 p-3 rounded border border-green-700 bg-green-900/30 text-sm text-green-200">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mt-4 p-3 rounded border border-red-700 bg-red-900/30 text-sm text-red-200">
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-4">
        @foreach ($sources as $key => $src)
            @php
                $state = $src['state'];
                $status = $state['status'] ?? null;
                $running = in_array($status, ['queued', 'running'], true);
                $statusTone = match ($status) {
                    'running', 'queued' => 'border-amber-700/60 bg-amber-950/30 text-amber-200',
                    'done' => 'border-emerald-700/60 bg-emerald-950/30 text-emerald-200',
                    'failed' => 'border-red-700/60 bg-red-900/30 text-red-200',
                    default => 'border-line bg-bg/40 text-muted',
                };
                $startedAt = isset($state['started_at']) ? \Illuminate\Support\Carbon::parse($state['started_at']) : null;
                $finishedAt = isset($state['finished_at']) && $state['finished_at']
                    ? \Illuminate\Support\Carbon::parse($state['finished_at']) : null;
            @endphp

            <section class="rounded-lg border border-line bg-panel overflow-hidden">
                <header class="px-5 py-3 border-b border-line">
                    <h2 class="font-semibold">{{ $src['label'] }}</h2>
                    <p class="text-xs text-muted mt-1">{{ $src['description'] }}</p>
                </header>

                <div class="px-5 py-4 space-y-3 text-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-xs uppercase tracking-wider text-muted">Last snapshot</span>
                        <span class="text-ink">
                            @if ($src['snapshot'])
                                {{ $src['snapshot']->captured_at->diffForHumans() }}
                                <span class="text-muted">/</span>
                                {{ $src['snapshot']->member_count ?? '?' }} members
                            @else
                                <span class="text-muted italic">none yet</span>
                            @endif
                        </span>
                    </div>

                    <div class="flex items-center justify-between">
                        <span class="text-xs uppercase tracking-wider text-muted">Cadence</span>
                        <span class="text-muted text-xs">{{ $src['cadence'] }}</span>
                    </div>

                    @if ($state)
                        <div class="rounded border {{ $statusTone }} p-3 text-xs">
                            <div class="flex items-center justify-between">
                                <strong class="uppercase tracking-wider">{{ $status }}</strong>
                                @if ($startedAt)
                                    <span class="text-muted">started {{ $startedAt->diffForHumans() }}</span>
                                @endif
                            </div>
                            @if ($status === 'failed' && ! empty($state['error']))
                                <div class="mt-2 font-mono text-[11px] break-all">{{ $state['error'] }}</div>
                            @endif
                            @if ($status === 'done' && ! empty($state['summary']))
                                <div class="mt-2 text-muted font-mono text-[11px]">
                                    @foreach ($state['summary'] as $k => $v)
                                        @if (! is_array($v))
                                            <span class="mr-3">{{ $k }}: {{ is_bool($v) ? ($v ? 'yes' : 'no') : $v }}</span>
                                        @endif
                                    @endforeach
                                    @if ($finishedAt && $startedAt)
                                        <span class="mr-3">took: {{ $startedAt->diffInSeconds($finishedAt) }}s</span>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endif

                    <div class="flex items-center gap-3 pt-1">
                        @if ($key === 'raiderio' && $src['has_button'])
                            <form method="POST" action="{{ route('admin.raiderio.sync') }}">
                                @csrf
                                <button type="submit"
                                        @disabled($running)
                                        class="text-sm px-3 py-2 rounded border border-line bg-bg hover:bg-panel disabled:opacity-50 disabled:cursor-not-allowed">
                                    {{ $running ? 'Syncing...' : 'Sync now' }}
                                </button>
                            </form>
                        @endif

                        @if ($src['has_upload'])
                            <form method="POST" action="{{ route('admin.sync.grm.upload') }}"
                                  enctype="multipart/form-data"
                                  class="flex items-center gap-2">
                                @csrf
                                <input type="file" name="grm_file" accept=".lua,.txt"
                                       class="text-xs text-muted file:bg-bg file:border file:border-line file:rounded file:text-ink file:text-xs file:px-2 file:py-1 file:mr-2 file:cursor-pointer">
                                <button type="submit"
                                        @disabled($running)
                                        class="text-sm px-3 py-2 rounded border border-line bg-bg hover:bg-panel disabled:opacity-50 disabled:cursor-not-allowed">
                                    Upload
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            </section>
        @endforeach
    </div>

    <div class="mt-8 text-xs text-muted">
        Background syncs run inside the same PHP process that handled the click,
        flushing the response first so the browser doesn't wait. Hostinger's
        per-request 30-60s ceiling still applies; if a sync needs to run longer
        than that, set up a queue worker
        (<code class="text-ink">php artisan queue:work --stop-when-empty --max-time=55</code>)
        triggered from the same cron that runs <code class="text-ink">schedule:run</code>.
    </div>
@endsection
