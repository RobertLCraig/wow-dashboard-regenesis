@extends('layouts.dashboard')

@section('title', 'General')

@section('content')
    <h1 class="text-xl font-semibold mb-6">General Guild Management</h1>

    {{-- Single responsive grid for the whole dashboard. Each widget
         declares its own col-span via its wrapper. Defaults below md
         collapse to one column for free. --}}
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
        <div class="col-span-full">
            @include('dashboard.widgets.roster-health', ['health' => $health])
        </div>

        <div class="col-span-full xl:col-span-2">
            @include('dashboard.widgets.action-queue', ['actionQueue' => $actionQueue])
        </div>
        <div class="col-span-full md:col-span-2 xl:col-span-1">
            @include('dashboard.widgets.upcoming-events', ['upcomingEvents' => $upcomingEvents])
        </div>

        <div class="col-span-full xl:col-span-2">
            @include('dashboard.widgets.team-progression', ['teamProgression' => $teamProgression])
        </div>
        <div>
            @include('dashboard.widgets.rank-distribution', ['rankDistribution' => $rankDistribution])
        </div>

        <div class="col-span-full">
            @include('dashboard.widgets.log-timeline', ['timeline' => $timeline])
        </div>

        <div>
            @include('dashboard.widgets.recently-inactive', ['inactive' => $inactive])
        </div>
        <div>
            @include('dashboard.widgets.alt-groups', ['altGroups' => $altGroups])
        </div>
        <div>
            @include('dashboard.widgets.anniversaries', ['anniversaries' => $anniversaries])
        </div>

        <div>
            @include('dashboard.widgets.bans', ['bans' => $bans])
        </div>
        <div class="col-span-full xl:col-span-2">
            @include('dashboard.widgets.churn', ['churn' => $churn])
        </div>
    </div>

    @if (! $lastSnapshot)
        <div class="mt-8 p-4 rounded border border-line bg-panel text-sm text-muted">
            No data ingested yet. Run <code class="text-ink">tools/grm-sync/grm-sync.ps1 -Force</code>
            on your PC after the next time you log in to WoW.
        </div>
    @endif
@endsection
