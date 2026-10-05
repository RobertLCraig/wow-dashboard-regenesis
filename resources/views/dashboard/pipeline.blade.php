@extends('layouts.dashboard')

@section('title', 'Trial pipeline')

@section('content')
    <h1 class="text-xl font-semibold mb-4">Trial pipeline</h1>

    @php
        $stages = [
            ['key' => 'applied', 'label' => 'Applied', 'rows' => $applied,
             'note' => 'New-recruits forms from the last '.\App\Http\Controllers\Dashboard\TrialPipelineController::APPLIED_WINDOW_DAYS.' days whose character is on no team.'],
            ['key' => 'trial', 'label' => 'Trial', 'rows' => $trial, 'note' => null],
            ['key' => 'raider', 'label' => 'Raider', 'rows' => $raider, 'note' => null],
            ['key' => 'alumni', 'label' => 'Alumni', 'rows' => $alumni,
             'note' => 'Team changes have only been recorded recently, so this column starts empty and fills over time.'],
        ];
    @endphp

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4 items-start">
        @foreach ($stages as $stage)
            <section data-stage="{{ $stage['key'] }}">
                <x-clarity-table :is-empty="$stage['rows']->isEmpty()" empty="Nobody here yet.">
                    <x-slot:header>
                        <h2 class="text-sm font-semibold uppercase tracking-wider">
                            {{ $stage['label'] }} <span data-count="{{ $stage['key'] }}">{{ $stage['rows']->count() }}</span>
                        </h2>
                    </x-slot:header>
                    @if ($stage['note'])
                        <x-slot:explainer>
                            <p class="px-4 pt-2 text-xs text-muted">{{ $stage['note'] }}</p>
                        </x-slot:explainer>
                    @endif

                    <table class="w-full text-sm clarity-tabular">
                        <thead class="sr-only">
                            <tr><th scope="col">Name</th><th scope="col">Detail</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($stage['rows'] as $row)
                                <tr data-row class="border-t border-line align-top">
                                    @if ($stage['key'] === 'applied')
                                        <td data-label="Name" class="px-4 py-2">
                                            <a href="{{ $row->discordUrl() }}" class="hover:underline" target="_blank" rel="noopener">{{ $row->character_name }}</a>
                                        </td>
                                        <td data-label="Form" class="px-4 py-2 text-muted text-xs whitespace-nowrap">{{ $row->posted_at?->format('Y-m-d') }}</td>
                                    @else
                                        @php($m = $row['member'])
                                        <td data-label="Name" class="px-4 py-2">
                                            <details>
                                                <summary class="cursor-pointer inline-flex items-center gap-1">
                                                    <x-class-icon :class="$m->class" />
                                                    <a href="{{ route('character.show', $m->name) }}" class="cls-{{ strtoupper($m->class ?? '') }} hover:underline">{{ $m->name }}</a>
                                                </summary>
                                                @if ($row['history']->isEmpty())
                                                    <p class="mt-1 text-xs text-muted">No team changes recorded.</p>
                                                @else
                                                    <ol class="mt-1 text-xs text-muted space-y-0.5">
                                                        @foreach ($row['history'] as $e)
                                                            <li>{{ $e->occurred_at->format('Y-m-d') }} {{ $e->type === \App\Models\MemberEvent::TYPE_TEAM_JOINED ? 'Joined' : 'Left' }} {{ \App\Models\TeamMapping::teamLabel($e->payload_json['team'] ?? null) }}</li>
                                                        @endforeach
                                                    </ol>
                                                @endif
                                            </details>
                                        </td>
                                        <td data-label="Detail" class="px-4 py-2 text-muted text-xs">
                                            @if ($stage['key'] === 'trial')
                                                {{ \App\Models\TeamMapping::teamLabel($row['team']) }}<br>
                                                {{ $row['days'] === null ? 'since before records' : $row['days'].' '.\Illuminate\Support\Str::plural('day', $row['days']) }}
                                            @elseif ($stage['key'] === 'raider')
                                                {{ \App\Models\TeamMapping::teamLabel($row['team']) }}
                                            @else
                                                {{ \App\Models\TeamMapping::teamLabel($row['team']) }}<br>
                                                left {{ $row['left_at']?->format('Y-m-d') ?? 'on an unknown date' }}
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-clarity-table>
            </section>
        @endforeach
    </div>
@endsection
