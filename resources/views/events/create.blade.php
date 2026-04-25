@extends('layouts.dashboard')

@section('title', 'New event')

@section('content')
<div class="max-w-2xl mx-auto">
    <h1 class="text-xl font-semibold mb-6">New event</h1>

    @if ($errors->any())
        <div class="mb-4 p-3 rounded bg-rose-500/10 border border-rose-500/30 text-rose-300 text-sm">
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('events.store') }}" class="space-y-4 bg-panel border border-line rounded-lg p-6">
        @csrf

        <div>
            <label class="block text-xs uppercase tracking-wider text-muted mb-1" for="title">Title</label>
            <input id="title" name="title" type="text" required
                   value="{{ old('title') }}"
                   class="w-full bg-bg border border-line rounded px-3 py-2 text-sm focus:outline-none focus:border-accent">
        </div>

        <div>
            <label class="block text-xs uppercase tracking-wider text-muted mb-1" for="description">Description</label>
            <textarea id="description" name="description" rows="4"
                      class="w-full bg-bg border border-line rounded px-3 py-2 text-sm focus:outline-none focus:border-accent">{{ old('description') }}</textarea>
            <p class="text-xs text-muted mt-1">Markdown supported. Discord renders it in the embed.</p>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-xs uppercase tracking-wider text-muted mb-1" for="starts_at">Starts at</label>
                <input id="starts_at" name="starts_at" type="datetime-local" required
                       value="{{ old('starts_at', now()->setTimezone(config('raidhelper.timezone'))->addDay()->format('Y-m-d\TH:i')) }}"
                       class="w-full bg-bg border border-line rounded px-3 py-2 text-sm focus:outline-none focus:border-accent">
                <p class="text-xs text-muted mt-1">Server tz: {{ config('raidhelper.timezone') }}</p>
            </div>

            <div>
                <label class="block text-xs uppercase tracking-wider text-muted mb-1" for="duration_minutes">Duration (minutes)</label>
                <input id="duration_minutes" name="duration_minutes" type="number" min="15" max="600" step="15" required
                       value="{{ old('duration_minutes', 180) }}"
                       class="w-full bg-bg border border-line rounded px-3 py-2 text-sm focus:outline-none focus:border-accent">
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-xs uppercase tracking-wider text-muted mb-1" for="template_id">Template</label>
                <select id="template_id" name="template_id" required
                        class="w-full bg-bg border border-line rounded px-3 py-2 text-sm focus:outline-none focus:border-accent">
                    @foreach ($templates as $tpl)
                        <option value="{{ $tpl['id'] }}" @selected(old('template_id') === $tpl['id'])>{{ $tpl['label'] }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-xs uppercase tracking-wider text-muted mb-1" for="channel_id">Discord channel ID</label>
                <input id="channel_id" name="channel_id" type="text" required
                       value="{{ old('channel_id', $defaultChannel) }}"
                       class="w-full bg-bg border border-line rounded px-3 py-2 text-sm focus:outline-none focus:border-accent">
                <p class="text-xs text-muted mt-1">Right-click channel in Discord -> Copy ID</p>
            </div>
        </div>

        <div>
            <label class="block text-xs uppercase tracking-wider text-muted mb-1" for="leader_id">Leader Discord ID</label>
            <input id="leader_id" name="leader_id" type="text" required
                   value="{{ old('leader_id', $leaderId) }}"
                   class="w-full bg-bg border border-line rounded px-3 py-2 text-sm focus:outline-none focus:border-accent">
            <p class="text-xs text-muted mt-1">Defaults to your own Discord ID.</p>
        </div>

        <div class="flex items-center justify-end gap-3 pt-2">
            <a href="{{ route('events.index') }}" class="text-sm text-muted hover:text-ink">Cancel</a>
            <button type="submit" class="px-4 py-2 rounded bg-accent text-white text-sm font-medium hover:bg-accent/80">Create event</button>
        </div>
    </form>
</div>
@endsection
