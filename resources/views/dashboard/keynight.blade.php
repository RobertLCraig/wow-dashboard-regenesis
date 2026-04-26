@extends('layouts.dashboard')

@section('title', 'Keynight (M+)')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold">Keynight (M+)</h1>
        <span class="text-xs text-muted">
            Channel: <code class="text-ink">{{ $preset['channel_id'] ?? 'unset' }}</code>
            @if (! empty($preset['raid_days']))
                <span class="text-line">|</span>
                Night: {{ implode(', ', array_map(fn ($d) => \Carbon\CarbonImmutable::now()->startOfWeek()->addDays($d - 1)->format('D'), $preset['raid_days'])) }}
            @endif
        </span>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2">
            <section class="bg-panel border border-line rounded-lg overflow-hidden">
                <header class="px-4 py-3 border-b border-line flex items-center justify-between">
                    <h2 class="text-sm font-semibold uppercase tracking-wider">
                        M+ Scoreboard
                        <span class="text-muted text-xs font-normal normal-case ml-2">
                            top {{ $scoreboard['rows']->count() }} by current-season RIO
                        </span>
                    </h2>
                    <span class="text-xs text-muted">
                        @if ($scoreboard['captured_at'])
                            raider.io {{ $scoreboard['captured_at']->diffForHumans() }}
                        @else
                            no raider.io data
                        @endif
                    </span>
                </header>
                @if ($scoreboard['rows']->isEmpty())
                    <div class="p-8 text-center text-muted text-sm">
                        No M+ data yet. Hit Sync now on
                        <a href="{{ route('admin.sync.index') }}" class="text-accent hover:underline">/admin/sync</a>
                        to pull from Raider.IO.
                    </div>
                @else
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wider text-muted">
                                <th class="px-4 py-2 w-8 text-right">#</th>
                                <th class="px-2 py-2">Character</th>
                                <th class="px-2 py-2 text-right">RIO</th>
                                <th class="px-2 py-2 text-right">Weekly key</th>
                                <th class="px-2 py-2 text-right">ilvl</th>
                                <th class="px-4 py-2 text-right">Links</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($scoreboard['rows'] as $i => $snap)
                                @php $cls = 'cls-' . strtoupper($snap->member->class ?? ''); @endphp
                                <tr class="border-t border-line">
                                    <td class="px-4 py-2 font-mono text-muted text-right">{{ $i + 1 }}</td>
                                    <td class="px-2 py-2 truncate max-w-[260px]">
                                        <span class="{{ $cls }}">{{ $snap->member->name }}</span>
                                    </td>
                                    <td class="px-2 py-2 font-mono text-right">
                                        {{ number_format($snap->mplus_score, 0) }}
                                    </td>
                                    <td class="px-2 py-2 font-mono text-right">
                                        {{ $snap->mplus_keystone !== null ? '+' . $snap->mplus_keystone : '-' }}
                                    </td>
                                    <td class="px-2 py-2 font-mono text-right">{{ $snap->ilvl ?? '-' }}</td>
                                    <td class="px-4 py-2 text-right">
                                        <x-character-links :member="$snap->member" />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>
        </div>

        <div class="space-y-6">
            @include('dashboard.widgets.quick-create', ['preset' => $preset])
            @include('dashboard.widgets.upcoming-events', ['upcomingEvents' => $upcomingEvents])
        </div>
    </div>
@endsection
