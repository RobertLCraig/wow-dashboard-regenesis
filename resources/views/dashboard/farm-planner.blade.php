@extends('layouts.dashboard')

@section('title', 'Farm planner')

@section('content')
    <div class="flex items-start justify-between gap-4 mb-4">
        <div>
            <h1 class="text-xl font-semibold">Farm planner</h1>
            <p class="text-sm text-muted mt-1">
                Pick a collectible by Blizzard ID. The list comes back split into
                "already has it" vs "still needs it" so you can decide whether a
                farm-event is worth running.
            </p>
        </div>
        @if ($capturedAt)
            <span class="text-xs text-muted whitespace-nowrap">
                collections data pulled {{ $capturedAt->diffForHumans() }}
            </span>
        @endif
    </div>

    <form method="GET" action="{{ route('farm-planner.index') }}"
          class="flex flex-wrap items-end gap-3 mb-6 p-4 rounded border border-line bg-panel">
        <label class="flex flex-col gap-1 text-xs">
            <span class="uppercase tracking-wider text-muted">Type</span>
            <select name="type" class="bg-bg border border-line rounded px-2 py-1.5 text-sm">
                @foreach ($types as $t)
                    <option value="{{ $t }}" @selected($type === $t)>{{ ucfirst($t) }}</option>
                @endforeach
            </select>
        </label>
        <label class="flex flex-col gap-1 text-xs">
            <span class="uppercase tracking-wider text-muted">Blizzard ID</span>
            <input type="number" name="id" min="1" required value="{{ $id }}"
                   placeholder="e.g. 2335 (Felsteel Annihilator)"
                   class="bg-bg border border-line rounded px-2 py-1.5 text-sm font-mono w-56" />
        </label>
        <button type="submit"
                class="text-sm px-3 py-2 rounded border border-line bg-bg hover:bg-panel">
            Find gaps
        </button>
        <span class="text-xs text-muted ml-auto">
            ID lookup: paste the URL of the Wowhead page for the mount/pet/toy and the
            number after <code>/spell=</code> or <code>/item=</code> is what you want.
        </span>
    </form>

    @if ($result === null)
        @if ($type === null || $id === null)
            <p class="text-sm text-muted italic">Pick a type and an ID to see the gap.</p>
        @else
            <p class="text-sm text-muted italic">
                No social snapshot yet. Run blizzard:pull-social (or wait for the weekly schedule)
                to populate collections data.
            </p>
        @endif
    @else
        @php
            $hasCount = count($result['has']);
            $missingCount = count($result['missing']);
            $noDataCount = count($result['no_data']);
        @endphp

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
            <div class="rounded border border-emerald-700/50 bg-emerald-950/20 p-3">
                <div class="text-2xl font-semibold text-emerald-300">{{ $hasCount }}</div>
                <div class="text-[10px] uppercase tracking-wider text-muted">has it</div>
            </div>
            <div class="rounded border border-amber-700/50 bg-amber-950/20 p-3">
                <div class="text-2xl font-semibold text-amber-300">{{ $missingCount }}</div>
                <div class="text-[10px] uppercase tracking-wider text-muted">still needs it</div>
            </div>
            <div class="rounded border border-line bg-bg p-3">
                <div class="text-2xl font-semibold">
                    {{ $result['coverage_pct'] !== null ? $result['coverage_pct'] . '%' : '-' }}
                </div>
                <div class="text-[10px] uppercase tracking-wider text-muted">guild coverage</div>
            </div>
            <div class="rounded border border-line bg-bg p-3">
                <div class="text-2xl font-semibold text-muted">{{ $noDataCount }}</div>
                <div class="text-[10px] uppercase tracking-wider text-muted">no data yet</div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <section class="rounded border border-amber-700/40 bg-bg p-4">
                <h2 class="text-sm font-semibold uppercase tracking-wider text-amber-200 mb-2">
                    Still needs it ({{ $missingCount }})
                </h2>
                @if ($missingCount === 0)
                    <p class="text-xs text-muted italic">Nobody is missing this. A farm event would be wasted effort.</p>
                @else
                    <ul class="grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
                        @foreach ($result['missing'] as $m)
                            @php $cls = 'cls-' . strtoupper($m['class'] ?? ''); @endphp
                            <li>
                                <a href="{{ route('character.show', $m['name']) }}"
                                   class="{{ $cls }} hover:underline">{{ $m['name'] }}</a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="rounded border border-line bg-bg p-4">
                <h2 class="text-sm font-semibold uppercase tracking-wider text-emerald-200 mb-2">
                    Already has it ({{ $hasCount }})
                </h2>
                @if ($hasCount === 0)
                    <p class="text-xs text-muted italic">Nobody in the guild has this yet.</p>
                @else
                    <details class="text-sm">
                        <summary class="cursor-pointer text-muted hover:text-ink select-none mb-2">
                            Show {{ $hasCount }}
                        </summary>
                        <ul class="grid grid-cols-2 gap-x-3 gap-y-1">
                            @foreach ($result['has'] as $m)
                                @php $cls = 'cls-' . strtoupper($m['class'] ?? ''); @endphp
                                <li>
                                    <a href="{{ route('character.show', $m['name']) }}"
                                       class="{{ $cls }} hover:underline">{{ $m['name'] }}</a>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </section>
        </div>

        @if ($noDataCount > 0)
            <p class="text-xs text-muted italic mt-4">
                {{ $noDataCount }} {{ \Illuminate\Support\Str::plural('member', $noDataCount) }}
                with no collections data yet (likely a fresh trial Blizzard hasn't returned a payload for).
            </p>
        @endif
    @endif
@endsection
