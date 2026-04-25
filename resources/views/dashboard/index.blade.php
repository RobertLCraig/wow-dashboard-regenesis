@extends('layouts.dashboard')

@section('title', 'Dashboard')

@section('content')
    @include('dashboard.widgets.roster-health', ['health' => $health])

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
        @include('dashboard.widgets.recently-inactive', ['inactive' => $inactive])
        @include('dashboard.widgets.log-timeline', ['timeline' => $timeline])
    </div>

    @if (! $lastSnapshot)
        <div class="mt-8 p-4 rounded border border-line bg-panel text-sm text-muted">
            No data ingested yet. Run <code class="text-ink">tools/grm-sync/grm-sync.ps1 -Force</code>
            on your PC after the next time you log in to WoW.
        </div>
    @endif
@endsection
