@extends('layouts.dashboard')

@section('title', 'Team mapping')

@section('content')
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold">Team mapping</h1>
            <p class="text-sm text-muted mt-1">
                Map in-game ranks and Discord role IDs to a raid team. Members and users
                pick up new mappings on the next GRM ingest / next role check.
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
            <strong>Couldn't save:</strong>
            <ul class="list-disc list-inside mt-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.teams.update') }}" class="mt-6 space-y-8">
        @csrf

        <section class="rounded-lg border border-line bg-panel">
            <header class="px-5 py-3 border-b border-line flex items-center justify-between">
                <h2 class="font-semibold">In-game ranks</h2>
                <span class="text-xs text-muted">From GRM SavedVariables. Includes every rank held by an active member.</span>
            </header>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs text-muted uppercase tracking-wider">
                        <tr>
                            <th class="text-left px-5 py-2">Rank</th>
                            <th class="text-left px-5 py-2">Members</th>
                            <th class="text-left px-5 py-2">Team</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rankRows as $i => $row)
                            <tr class="border-t border-line">
                                <td class="px-5 py-2">
                                    <code class="text-ink">{{ $row['key'] }}</code>
                                    <input type="hidden" name="ranks[{{ $i }}][key]" value="{{ $row['key'] }}">
                                </td>
                                <td class="px-5 py-2 text-muted">
                                    @if ($row['count'] > 0)
                                        {{ $row['count'] }}
                                    @else
                                        <span class="text-xs italic">not in roster</span>
                                    @endif
                                </td>
                                <td class="px-5 py-2">
                                    <select name="ranks[{{ $i }}][team]"
                                            class="bg-bg border border-line rounded px-2 py-1 text-sm">
                                        <option value="">Unassigned</option>
                                        @foreach ($teams as $team)
                                            <option value="{{ $team }}"
                                                    @selected(optional($row['mapping'])->team === $team)>
                                                {{ \App\Models\TeamMapping::teamLabel($team) }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-5 py-4 text-center text-muted text-xs italic">
                                No ranks observed yet. Run the GRM sync first.
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-lg border border-line bg-panel">
            <header class="px-5 py-3 border-b border-line flex items-center justify-between">
                <h2 class="font-semibold">Discord roles</h2>
                <span class="text-xs text-muted">
                    To copy a role ID: enable Developer Mode in Discord (User Settings &rsaquo; Advanced),
                    then right-click the role &rsaquo; Copy ID.
                </span>
            </header>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs text-muted uppercase tracking-wider">
                        <tr>
                            <th class="text-left px-5 py-2">Role ID</th>
                            <th class="text-left px-5 py-2">Label</th>
                            <th class="text-left px-5 py-2">Team</th>
                            <th class="text-left px-5 py-2">Priority</th>
                            <th class="text-left px-5 py-2">Delete</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($roleMappings as $i => $m)
                            <tr class="border-t border-line">
                                <td class="px-5 py-2">
                                    <code class="text-ink">{{ $m->key }}</code>
                                    <input type="hidden" name="roles[{{ $i }}][key]" value="{{ $m->key }}">
                                </td>
                                <td class="px-5 py-2">
                                    <input type="text" name="roles[{{ $i }}][label]" value="{{ $m->label }}"
                                           class="bg-bg border border-line rounded px-2 py-1 text-sm w-48">
                                </td>
                                <td class="px-5 py-2">
                                    <select name="roles[{{ $i }}][team]"
                                            class="bg-bg border border-line rounded px-2 py-1 text-sm">
                                        <option value="">Unassigned</option>
                                        @foreach ($teams as $team)
                                            <option value="{{ $team }}" @selected($m->team === $team)>
                                                {{ \App\Models\TeamMapping::teamLabel($team) }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="px-5 py-2">
                                    <input type="number" name="roles[{{ $i }}][priority]" value="{{ $m->priority }}"
                                           min="0" max="1000"
                                           class="bg-bg border border-line rounded px-2 py-1 text-sm w-20">
                                </td>
                                <td class="px-5 py-2">
                                    <label class="text-xs text-muted flex items-center gap-1">
                                        <input type="checkbox" name="delete_role_ids[]" value="{{ $m->id }}"
                                               class="bg-bg border border-line rounded">
                                        remove
                                    </label>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-5 py-4 text-center text-muted text-xs italic">
                                No Discord roles mapped yet. Add one below.
                            </td></tr>
                        @endforelse
                        <tr class="border-t border-line bg-bg/50">
                            <td class="px-5 py-2">
                                <input type="text" name="new_role[key]" placeholder="123456789012345678"
                                       pattern="[0-9]{15,25}"
                                       class="bg-bg border border-line rounded px-2 py-1 text-sm w-48">
                            </td>
                            <td class="px-5 py-2">
                                <input type="text" name="new_role[label]" placeholder="Heroic Raider"
                                       class="bg-bg border border-line rounded px-2 py-1 text-sm w-48">
                            </td>
                            <td class="px-5 py-2">
                                <select name="new_role[team]"
                                        class="bg-bg border border-line rounded px-2 py-1 text-sm">
                                    <option value="">Unassigned</option>
                                    @foreach ($teams as $team)
                                        <option value="{{ $team }}">{{ \App\Models\TeamMapping::teamLabel($team) }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="px-5 py-2">
                                <input type="number" name="new_role[priority]" value="100" min="0" max="1000"
                                       class="bg-bg border border-line rounded px-2 py-1 text-sm w-20">
                            </td>
                            <td class="px-5 py-2 text-xs text-muted italic">add new</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="flex justify-end">
            <button type="submit"
                    class="px-4 py-2 rounded bg-accent text-white text-sm font-medium hover:opacity-90">
                Save mappings
            </button>
        </div>
    </form>
@endsection
