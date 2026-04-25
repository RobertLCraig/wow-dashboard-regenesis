@extends('layouts.dashboard')

@section('title', 'New event')

@section('content')
@php
    // Initial state for the channel + duration toggles. old('...') retains
    // the user's last input on validation failure; otherwise we pick
    // sensible defaults that match the most common officer flow.
    $channelIds = collect($channels ?? [])->pluck('id');
    $oldChannelId = (string) old('channel_id', $defaultChannel ?? '');
    $channelMode = old('_channel_mode',
        $oldChannelId !== '' && ! $channelIds->contains($oldChannelId) ? 'other' : 'preset'
    );
    $durationMode = old('duration_mode', 'duration');
@endphp
<div class="max-w-2xl mx-auto">
    <h1 class="text-xl font-semibold mb-6">New event</h1>

    @if (isset($errors) && $errors->any())
        <div class="mb-4 p-3 rounded bg-rose-500/10 border border-rose-500/30 text-rose-300 text-sm">
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('events.store') }}"
          x-data="{
              channelMode: '{{ $channelMode }}',
              channelPreset: '{{ $channelMode === 'preset' ? $oldChannelId : '' }}',
              channelOther: '{{ $channelMode === 'other' ? $oldChannelId : '' }}',
              durationMode: '{{ $durationMode }}',
          }"
          class="space-y-4 bg-panel border border-line rounded-lg p-6">
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

        <div>
            <label class="block text-xs uppercase tracking-wider text-muted mb-1" for="starts_at">Starts at</label>
            <input id="starts_at" name="starts_at" type="datetime-local" required
                   value="{{ old('starts_at', now()->setTimezone(config('raidhelper.timezone'))->addDay()->format('Y-m-d\TH:i')) }}"
                   class="w-full bg-bg border border-line rounded px-3 py-2 text-sm focus:outline-none focus:border-accent">
            <p class="text-xs text-muted mt-1">Server tz: {{ config('raidhelper.timezone') }}</p>
        </div>

        {{-- Three duration modes. The mode determines which of
             duration_minutes / ends_at the form submits; only one is
             ever sent so the controller's required_if covers it. --}}
        <div>
            <label class="block text-xs uppercase tracking-wider text-muted mb-1">Duration</label>
            <div class="flex flex-wrap gap-2 text-sm">
                <label class="flex items-center gap-2 px-3 py-1.5 rounded border border-line bg-bg cursor-pointer"
                       :class="durationMode === 'duration' ? 'border-accent text-ink' : 'text-muted hover:text-ink'">
                    <input type="radio" name="duration_mode" value="duration"
                           x-model="durationMode" class="accent-accent">
                    Duration
                </label>
                <label class="flex items-center gap-2 px-3 py-1.5 rounded border border-line bg-bg cursor-pointer"
                       :class="durationMode === 'end_time' ? 'border-accent text-ink' : 'text-muted hover:text-ink'">
                    <input type="radio" name="duration_mode" value="end_time"
                           x-model="durationMode" class="accent-accent">
                    End time
                </label>
                <label class="flex items-center gap-2 px-3 py-1.5 rounded border border-line bg-bg cursor-pointer"
                       :class="durationMode === 'default' ? 'border-accent text-ink' : 'text-muted hover:text-ink'">
                    <input type="radio" name="duration_mode" value="default"
                           x-model="durationMode" class="accent-accent">
                    Default
                </label>
            </div>

            <div x-show="durationMode === 'duration'" x-cloak class="mt-2">
                <input id="duration_minutes" name="duration_minutes" type="number"
                       min="15" max="1440" step="15"
                       :required="durationMode === 'duration'"
                       value="{{ old('duration_minutes', 180) }}"
                       class="w-full bg-bg border border-line rounded px-3 py-2 text-sm focus:outline-none focus:border-accent">
                <p class="text-xs text-muted mt-1">Length in minutes (15 - 1440).</p>
            </div>

            <div x-show="durationMode === 'end_time'" x-cloak class="mt-2">
                <input id="ends_at" name="ends_at" type="datetime-local"
                       :required="durationMode === 'end_time'"
                       value="{{ old('ends_at') }}"
                       class="w-full bg-bg border border-line rounded px-3 py-2 text-sm focus:outline-none focus:border-accent">
                <p class="text-xs text-muted mt-1">Must be after start. Same tz as Starts at.</p>
            </div>

            <p x-show="durationMode === 'default'" x-cloak class="mt-2 text-xs text-muted">
                Raid-Helper applies the default from your server's settings (typically 3 hours).
            </p>
        </div>

        <div>
            <label class="block text-xs uppercase tracking-wider text-muted mb-1" for="template_id">Template</label>
            <select id="template_id" name="template_id" required
                    class="w-full bg-bg border border-line rounded px-3 py-2 text-sm focus:outline-none focus:border-accent">
                @foreach ($templates as $tpl)
                    <option value="{{ $tpl['id'] }}" @selected(old('template_id') === $tpl['id'])>{{ $tpl['label'] }}</option>
                @endforeach
            </select>
        </div>

        {{-- Channel: dropdown of known channels with an "Other..." escape
             hatch for one-off ids. The hidden input is what actually gets
             submitted; Alpine populates it from whichever path the user
             picked. --}}
        <div>
            <label class="block text-xs uppercase tracking-wider text-muted mb-1">Channel</label>

            <div x-show="channelMode === 'preset'" x-cloak>
                <select x-model="channelPreset"
                        @change="channelMode = $event.target.value === '__other__' ? 'other' : 'preset'"
                        class="w-full bg-bg border border-line rounded px-3 py-2 text-sm focus:outline-none focus:border-accent">
                    @foreach ($channels as $ch)
                        <option value="{{ $ch['id'] }}">{{ $ch['label'] }}</option>
                    @endforeach
                    <option value="__other__">Other... (paste ID)</option>
                </select>
            </div>

            <div x-show="channelMode === 'other'" x-cloak class="flex gap-2">
                <input type="text" inputmode="numeric" x-model="channelOther"
                       placeholder="Paste Discord channel ID"
                       class="flex-1 bg-bg border border-line rounded px-3 py-2 text-sm focus:outline-none focus:border-accent">
                <button type="button" @click="channelMode = 'preset'; channelOther = ''"
                        class="px-3 py-2 text-xs text-muted hover:text-ink border border-line rounded">
                    &larr; Back to list
                </button>
            </div>

            {{-- The actual submitted value: whichever of preset/other is
                 currently active. Hidden so server-side validation runs
                 on the regex-checked snowflake. --}}
            <input type="hidden" name="channel_id"
                   :value="channelMode === 'preset' ? channelPreset : channelOther">
            {{-- Mirror the mode for old() repopulation on validation
                 failure. The controller doesn't care about this field. --}}
            <input type="hidden" name="_channel_mode" :value="channelMode">

            <p class="text-xs text-muted mt-1">
                Right-click a channel in Discord with Developer Mode on to copy its ID.
            </p>
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
