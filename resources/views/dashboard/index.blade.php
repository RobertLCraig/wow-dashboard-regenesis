@extends('layouts.dashboard')

@section('title', 'Dashboard')

@section('content')
    @include('dashboard.widgets.roster-health', ['health' => $health])

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
        @include('dashboard.widgets.upcoming-events', ['upcomingEvents' => $upcomingEvents])
        @include('dashboard.widgets.attendance', ['attendance' => $attendance])
    </div>

    <div class="mt-6">
        @include('dashboard.widgets.action-queue', ['actionQueue' => $actionQueue])
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
        @include('dashboard.widgets.anniversaries', ['anniversaries' => $anniversaries])
        @include('dashboard.widgets.recently-inactive', ['inactive' => $inactive])
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
        @include('dashboard.widgets.alt-groups', ['altGroups' => $altGroups])
        @include('dashboard.widgets.log-timeline', ['timeline' => $timeline])
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
        @include('dashboard.widgets.bans', ['bans' => $bans])
        @include('dashboard.widgets.rank-distribution', ['rankDistribution' => $rankDistribution])
    </div>

    <div class="mt-6">
        @include('dashboard.widgets.churn', ['churn' => $churn])
    </div>

    @if (! $lastSnapshot)
        <div class="mt-8 p-4 rounded border border-line bg-panel text-sm text-muted">
            No data ingested yet. Run <code class="text-ink">tools/grm-sync/grm-sync.ps1 -Force</code>
            on your PC after the next time you log in to WoW.
        </div>
    @endif
@endsection
