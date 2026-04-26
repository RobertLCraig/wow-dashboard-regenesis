@extends('layouts.dashboard')

@section('title', 'Mythic Team')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold">Mythic Team</h1>
        <span class="text-xs text-muted">
            Channel: <code class="text-ink">{{ $preset['channel_id'] ?? 'unset' }}</code>
            @if (! empty($preset['raid_days']))
                <span class="text-line">|</span>
                Raid days: {{ implode(', ', array_map(fn ($d) => \Carbon\CarbonImmutable::now()->startOfWeek()->addDays($d - 1)->format('D'), $preset['raid_days'])) }}
            @endif
        </span>
    </div>

    @include('dashboard.widgets.team-raid-summary', ['raidSummary' => $raidSummary])

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mt-6">
        <div class="lg:col-span-2">
            @include('dashboard.widgets.team-roster', ['roster' => $roster])
        </div>
        <div class="space-y-6">
            @include('dashboard.widgets.quick-create', ['preset' => $preset, 'teamSlug' => $teamSlug])
            @include('dashboard.widgets.upcoming-events', ['upcomingEvents' => $upcomingEvents])
        </div>
    </div>
@endsection
