@extends('layouts.dashboard')

@section('title', 'Team schedule')

@section('content')
@php
    // ISO weekday labels for the day-pickers. Shown in starts-Monday
    // order matching CarbonImmutable::dayOfWeekIso (1..7).
    $weekdays = [
        1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu',
        5 => 'Fri', 6 => 'Sat', 7 => 'Sun',
    ];
@endphp

    <div class="flex items-start justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-semibold">Team schedule</h1>
            <p class="text-sm text-muted mt-1">
                Per-team event days and start time. Drives the "Next Tue / Next Thu" pills on
                the quick-create panels and any future scheduled-events views. Overrides apply
                immediately; reset returns to the static <code class="text-ink">config/raidhelper.php</code> defaults.
            </p>
        </div>
        <a href="{{ route('dashboard') }}" class="text-sm text-accent hover:underline shrink-0">Back to dashboard</a>
    </div>

    @if (session('status'))
        <div class="mb-4 p-3 rounded bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-sm">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 p-3 rounded bg-rose-500/10 border border-rose-500/30 text-rose-300 text-sm">
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (empty($teams))
        <div class="p-6 rounded border border-line bg-panel text-sm text-muted">
            No teams declared in <code class="text-ink">config('raidhelper.teams')</code>. Add one there
            first, then come back to set its schedule.
        </div>
    @else
        <form method="POST" action="{{ route('admin.teams.schedule.update') }}" class="space-y-4">
            @csrf

            @foreach ($teams as $slug => $team)
                @php
                    $selected = old("teams.{$slug}.raid_days", $team['raid_days'] ?? []);
                    $time = old("teams.{$slug}.raid_time", $team['raid_time'] ?? '19:30');
                @endphp
                <section class="rounded-lg border border-line bg-panel p-5">
                    <header class="flex items-center justify-between gap-3 mb-4">
                        <div>
                            <h2 class="font-semibold">
                                {{ $team['label'] ?? \Illuminate\Support\Str::title($slug) }}
                                <code class="text-xs text-muted ml-2">{{ $slug }}</code>
                            </h2>
                            <p class="text-xs text-muted mt-0.5">
                                @if ($team['source'] === 'override')
                                    <span class="text-amber-300">Custom schedule</span> | overrides config defaults
                                @else
                                    Using <span class="text-ink">config defaults</span>
                                @endif
                            </p>
                        </div>
                        @if ($team['source'] === 'override')
                            <button type="submit"
                                    formaction="{{ route('admin.teams.schedule.reset', $slug) }}"
                                    formmethod="POST"
                                    class="text-xs px-3 py-1.5 rounded border border-line text-muted hover:text-ink hover:border-muted">
                                Reset to config
                            </button>
                        @endif
                    </header>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                        <div class="md:col-span-2">
                            <label class="block text-xs uppercase tracking-wider text-muted mb-2">Event days</label>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($weekdays as $iso => $label)
                                    @php $isOn = in_array($iso, $selected, false); @endphp
                                    <label class="cursor-pointer">
                                        <input type="checkbox"
                                               name="teams[{{ $slug }}][raid_days][]"
                                               value="{{ $iso }}"
                                               class="peer sr-only"
                                               @checked($isOn)>
                                        <span class="inline-block px-3 py-1.5 rounded border text-sm transition
                                                     border-line text-muted hover:text-ink
                                                     peer-checked:border-accent peer-checked:bg-accent/15 peer-checked:text-ink">
                                            {{ $label }}
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            <p class="text-xs text-muted mt-2">Click days to toggle. Empty list = no scheduled event nights.</p>
                        </div>

                        <div>
                            <label class="block text-xs uppercase tracking-wider text-muted mb-2" for="time-{{ $slug }}">Start time</label>
                            <input id="time-{{ $slug }}" type="time"
                                   name="teams[{{ $slug }}][raid_time]"
                                   value="{{ $time }}"
                                   required
                                   class="w-full bg-bg border border-line rounded px-3 py-2 text-sm focus:outline-none focus:border-accent">
                            <p class="text-xs text-muted mt-2">
                                Time in <strong>{{ config('raidhelper.timezone') }}</strong>.
                            </p>
                        </div>
                    </div>
                </section>
            @endforeach

            <div class="flex justify-end">
                <button type="submit" class="px-4 py-2 rounded bg-accent text-white text-sm font-medium hover:bg-accent/80">
                    Save schedule
                </button>
            </div>
        </form>
    @endif
@endsection
