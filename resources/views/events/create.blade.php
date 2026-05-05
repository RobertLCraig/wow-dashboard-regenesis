@extends('layouts.dashboard')

@section('title', 'New event')

@section('content')
@php
    // Initial state for the channel + duration toggles. old('...') retains
    // the user's last input on validation failure; otherwise we pick
    // sensible defaults that match the most common officer flow.
    $channelIds = collect($channels ?? [])->pluck('id');
    $firstChannelId = (string) ($channels[0]['id'] ?? '');
    // ?: not ?? because env('RAID_HELPER_DEFAULT_CHANNEL_ID') with an
    // empty .env line returns '' (not null), so ?? would skip the
    // fallback and leave $oldChannelId blank - which then breaks the
    // channel-name lookup below and leaves the announcement rows
    // pointing at "" instead of the configured first preset.
    $oldChannelId = (string) old('channel_id', $defaultChannel ?: $firstChannelId);
    $channelMode = old('_channel_mode',
        $oldChannelId !== '' && ! $channelIds->contains($oldChannelId) ? 'other' : 'preset'
    );
    $presetFallback = $channelIds->contains($defaultChannel ?: '') ? $defaultChannel : $firstChannelId;
    $durationMode = old('duration_mode', 'duration');

    // Tomorrow at the configured default time, in the configured
    // (WoW EU realm) timezone.
    [$defH, $defM] = array_map('intval', explode(':', config('raidhelper.default_time_of_day', '19:30') . ':0'));
    $startsAtDefault = now()
        ->setTimezone(config('raidhelper.timezone'))
        ->addDay()
        ->setTime($defH, $defM);

    // Reminder pings always get posted into the same Discord channel as
    // the event signup itself, so derive the default name from the
    // currently selected signup channel. Falls back to empty if the
    // officer pasted an arbitrary ID we don't have a name for.
    $defaultAnnouncementChannelName = collect($channels ?? [])
        ->firstWhere('id', $oldChannelId)['name'] ?? '';

    $oldAnnouncements = old('announcements');
    $initialAnnouncements = is_array($oldAnnouncements) && count($oldAnnouncements) > 0
        ? $oldAnnouncements
        : array_map(fn ($a) => [
            'minutes' => $a['minutes'],
            'message' => $a['message'],
            'channel' => $defaultAnnouncementChannelName,
        ], $defaultAnnouncements);

    // {channel_id: [role names]}, supplied by the controller from
    // DiscordRoleMentionResolver. Drives the mentions pre-fill and the
    // /quickcreate paste-fallback preview. Channels not in the map
    // (other-... custom IDs, dj-stuff) ping nobody.
    $mentionsByChannel = $mentionsByChannel ?? [];

    // Pre-fill for the mentions text input: use old() on re-render after
    // validation failure, otherwise seed from the default channel's roles.
    $initialMentions = old('mentions', implode(', ', $mentionsByChannel[$oldChannelId] ?? []));

    $allPingableRoleNames = $allPingableRoleNames ?? [];
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
          x-data='{
              channels: @json($channels),
              mentionsByChannel: @json((object) $mentionsByChannel),
              channelMode: @json($channelMode),
              channelPreset: @json($channelMode === "preset" ? $oldChannelId : $presetFallback),
              channelOther: @json($channelMode === "other" ? $oldChannelId : ""),
              presetFallback: @json($presetFallback),
              durationMode: @json($durationMode),
              title: @json(old("title", "")),
              description: @json(old("description", "")),
              startsAt: @json(old("starts_at", $startsAtDefault->format("Y-m-d\TH:i"))),
              durationMinutes: @json(old("duration_minutes", 180)),
              endsAt: @json(old("ends_at", "")),
              templateId: @json(old("template_id", "6")),
              leaderId: @json(old("leader_id", $leaderId)),
              announcements: @json($initialAnnouncements),
              mentions: @json($initialMentions),
              mentionsDirty: false,
              init() {
                  // Keep announcement rows pointed at the signup channel.
                  // The x-data attribute is wrapped in single quotes, so
                  // no apostrophes in this comment block.
                  this.$watch("effectiveChannelName", (next) => {
                      if (!next) return;
                      this.announcements = this.announcements.map(a => ({ ...a, channel: next }));
                  });
                  // Auto-update mentions when the channel changes, unless
                  // the officer has manually edited the field.
                  this.$watch("effectiveMentionNames", (next) => {
                      if (!this.mentionsDirty) {
                          this.mentions = next.join(", ");
                      }
                  });
              },
              addAnnouncement() { this.announcements.push({ minutes: 60, message: "Event starting in 1 hour!", channel: this.effectiveChannelName || "" }); },
              removeAnnouncement(i) { this.announcements.splice(i, 1); },
              get effectiveChannelId() { return this.channelMode === "preset" ? this.channelPreset : this.channelOther; },
              get effectiveChannelName() {
                  const found = this.channels.find(c => c.id === this.effectiveChannelId);
                  return found ? found.name : null;
              },
              get effectiveMentionNames() {
                  return this.mentionsByChannel[this.effectiveChannelId] || [];
              },
              get formattedStartsAt() {
                  // datetime-local "YYYY-MM-DDTHH:MM" -> Raid-Helper "DD-MM-YYYY HH:MM"
                  if (!this.startsAt || !this.startsAt.includes("T")) return "";
                  const [date, time] = this.startsAt.split("T");
                  const [y, m, d] = date.split("-");
                  return `${d}-${m}-${y} ${time}`;
              },
              get quickcreateCommand() {
                  const parts = [];
                  if (this.templateId) parts.push(`[template: ${this.templateId}]`);
                  if (this.title) parts.push(`[title: ${this.title}]`);
                  if (this.description) {
                      // Discord arg parser doesnt like real newlines; collapse to spaces.
                      const desc = this.description.replace(/\n+/g, " ").trim();
                      if (desc) parts.push(`[description: ${desc}]`);
                  }
                  const chName = this.effectiveChannelName;
                  if (chName) parts.push(`[channel: #${chName}]`);
                  if (this.durationMode === "duration" && this.durationMinutes) {
                      parts.push(`[advanced: <duration: ${this.durationMinutes}>]`);
                  }
                  if (this.mentions && this.mentions.trim()) {
                      parts.push(`[mentions: ${this.mentions.trim()}]`);
                  }
                  for (const a of this.announcements) {
                      if (a && a.message && a.minutes && a.channel) {
                          parts.push(`[announcement: ${a.channel}, ${a.minutes}, ${a.message}]`);
                      }
                  }
                  const dt = this.formattedStartsAt;
                  return `/quickcreate arguments: ${parts.join(" ")}` + (dt ? ` date_and_time: ${dt}` : "");
              },
          }'
          class="space-y-4 bg-panel border border-line rounded-lg p-6">
        @csrf

        <div>
            <label class="block text-xs uppercase tracking-wider text-muted mb-1" for="title">Title</label>
            <input id="title" name="title" type="text" required
                   x-model="title"
                   class="w-full bg-bg border border-line rounded px-3 py-2 text-sm focus:outline-none focus:border-accent">
        </div>

        <div>
            <label class="block text-xs uppercase tracking-wider text-muted mb-1" for="description">Description</label>
            <textarea id="description" name="description" rows="4"
                      x-model="description"
                      class="w-full bg-bg border border-line rounded px-3 py-2 text-sm focus:outline-none focus:border-accent"></textarea>
            <p class="text-xs text-muted mt-1">Markdown supported. Discord renders it in the embed.</p>
        </div>

        <div>
            <label class="block text-xs uppercase tracking-wider text-muted mb-1" for="starts_at">Starts at</label>
            <input id="starts_at" name="starts_at" type="datetime-local" required
                   x-model="startsAt"
                   class="w-full bg-bg border border-line rounded px-3 py-2 text-sm focus:outline-none focus:border-accent">
            <p class="text-xs text-muted mt-1">Time is in <strong>{{ config('raidhelper.timezone') }}</strong> (WoW EU realm time)</p>
        </div>

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
                       x-model.number="durationMinutes"
                       class="w-full bg-bg border border-line rounded px-3 py-2 text-sm focus:outline-none focus:border-accent">
                <p class="text-xs text-muted mt-1">Length in minutes (15 - 1440).</p>
            </div>

            <div x-show="durationMode === 'end_time'" x-cloak class="mt-2">
                <input id="ends_at" name="ends_at" type="datetime-local"
                       :required="durationMode === 'end_time'"
                       x-model="endsAt"
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
                    x-model="templateId"
                    class="w-full bg-bg border border-line rounded px-3 py-2 text-sm focus:outline-none focus:border-accent">
                @foreach ($templates as $tpl)
                    <option value="{{ $tpl['id'] }}">{{ $tpl['label'] }}</option>
                @endforeach
            </select>
            <details class="mt-2 text-xs text-muted">
                <summary class="cursor-pointer hover:text-ink">What's a template? (signup mechanics reference)</summary>
                <p class="mt-2">
                    Templates set <em>how members sign up</em>, not event size. Raid-Helper's own docs only formally describe the premium custom-template flow (IDs 17+); the built-in IDs below are what we've observed in our own events, not guaranteed Raid-Helper canon. Verify before adding a new one to the dropdown.
                </p>
                <ul class="mt-2 space-y-1 list-disc list-inside">
                    <li><strong>1</strong>: Accept / Maybe / Decline. We use this for socials.</li>
                    <li><strong>2-5</strong>: Class picker + bench / late / tentative / absence (game-specific variants we haven't confirmed).</li>
                    <li><strong>6</strong>: Role picker (tank / melee / ranged / healer) + the usual bench / late / tentative / absence. We use this for raids.</li>
                    <li><strong>7</strong>: Same as 6 plus a Support role.</li>
                    <li><strong>8</strong>: Yes-only + bench / late / tentative / absence.</li>
                    <li><strong>9</strong>: Bench / late / tentative / absence only (no role or class picker).</li>
                </ul>
                <p class="mt-2">
                    To expose another template, add it to <code class="text-[11px]">config/raidhelper.php</code> under <code class="text-[11px]">templates</code>. Test it on a throwaway event in a private channel first.
                </p>
            </details>
        </div>

        <div>
            <label class="block text-xs uppercase tracking-wider text-muted mb-1">Channel</label>

            <div x-show="channelMode === 'preset'" x-cloak>
                <select name="channel_id" x-model="channelPreset"
                        :disabled="channelMode !== 'preset'"
                        @change="if ($event.target.value === '__other__') { channelMode = 'other'; channelPreset = ''; }"
                        class="w-full bg-bg border border-line rounded px-3 py-2 text-sm focus:outline-none focus:border-accent">
                    @foreach ($channels as $ch)
                        <option value="{{ $ch['id'] }}">{{ $ch['label'] }}</option>
                    @endforeach
                    <option value="__other__">Other... (paste ID)</option>
                </select>
            </div>

            <div x-show="channelMode === 'other'" x-cloak class="flex gap-2">
                <input type="text" inputmode="numeric" name="channel_id"
                       x-model="channelOther"
                       :disabled="channelMode !== 'other'"
                       placeholder="Paste Discord channel ID"
                       class="flex-1 bg-bg border border-line rounded px-3 py-2 text-sm focus:outline-none focus:border-accent">
                <button type="button"
                        @click="channelMode = 'preset'; channelOther = ''; channelPreset = presetFallback"
                        class="px-3 py-2 text-xs text-muted hover:text-ink border border-line rounded">
                    &larr; Back to list
                </button>
            </div>

            <input type="hidden" name="_channel_mode" :value="channelMode">

            <p class="text-xs text-muted mt-1">
                Right-click a channel in Discord with Developer Mode on to copy its ID.
            </p>
        </div>

        <div>
            <label class="block text-xs uppercase tracking-wider text-muted mb-1" for="mentions">Mentions</label>
            <input id="mentions" name="mentions" type="text" list="pingable-roles"
                   x-model="mentions"
                   @input="mentionsDirty = true"
                   placeholder="Role Name One, Role Name Two"
                   class="w-full bg-bg border border-line rounded px-3 py-2 text-sm focus:outline-none focus:border-accent">
            <datalist id="pingable-roles">
                @foreach ($allPingableRoleNames as $roleName)
                    <option value="{{ $roleName }}">
                @endforeach
            </datalist>
            <p class="text-xs text-muted mt-1">
                Comma-separated role names to ping when the event posts. Auto-fills from channel; edit to override. Clear to send no pings.
            </p>
        </div>

        <div>
            <label class="block text-xs uppercase tracking-wider text-muted mb-1" for="leader_id">Leader Discord ID</label>
            <input id="leader_id" name="leader_id" type="text" required
                   x-model="leaderId"
                   class="w-full bg-bg border border-line rounded px-3 py-2 text-sm focus:outline-none focus:border-accent">
            <p class="text-xs text-muted mt-1">Defaults to your own Discord ID.</p>
        </div>

        {{-- Reminder pings. Each row submits as
             announcements[i][minutes|message|channel] which the
             controller validates and sends to Raid-Helper as both an
             `announcements` array and a singular `announcement` object
             (the API docs only formally describe the singular). --}}
        <div>
            <div class="flex items-center justify-between mb-1">
                <label class="block text-xs uppercase tracking-wider text-muted">Reminder pings (announcements)</label>
                <button type="button" @click="addAnnouncement()"
                        class="text-xs text-accent hover:underline">+ Add reminder</button>
            </div>
            <div class="space-y-2">
                <template x-for="(a, idx) in announcements" :key="idx">
                    <div class="flex gap-2 items-start">
                        <div class="w-20">
                            <input type="number" min="1" max="43200" step="1"
                                   :name="`announcements[${idx}][minutes]`"
                                   x-model.number="a.minutes"
                                   placeholder="mins"
                                   class="w-full bg-bg border border-line rounded px-2 py-1.5 text-sm focus:outline-none focus:border-accent">
                        </div>
                        <input type="text"
                               :name="`announcements[${idx}][message]`"
                               x-model="a.message"
                               placeholder="Message text"
                               class="flex-1 bg-bg border border-line rounded px-2 py-1.5 text-sm focus:outline-none focus:border-accent">
                        <input type="text"
                               :name="`announcements[${idx}][channel]`"
                               x-model="a.channel"
                               placeholder="channel-name"
                               class="w-40 bg-bg border border-line rounded px-2 py-1.5 text-sm focus:outline-none focus:border-accent font-mono text-xs">
                        <button type="button" @click="removeAnnouncement(idx)"
                                title="Remove this reminder"
                                class="text-rose-400 hover:text-rose-300 px-2 py-1.5">×</button>
                    </div>
                </template>
            </div>
            <p class="text-xs text-muted mt-1">
                <strong>Minutes</strong> = how long before event start to ping.
                <strong>Channel</strong> auto-tracks the signup channel above (change the signup channel and these follow).
                Empty rows are ignored on submit.
            </p>
        </div>

        <div class="flex items-center justify-end gap-3 pt-2">
            <a href="{{ route('events.index') }}" class="text-sm text-muted hover:text-ink">Cancel</a>
            <button type="submit" class="px-4 py-2 rounded bg-accent text-white text-sm font-medium hover:bg-accent/80">Create event</button>
        </div>

        {{-- Always-correct fallback: the Discord /quickcreate command
             with every field filled in. Officer can copy + paste this
             into any Raid-Helper-enabled channel as a fallback if the
             API path rejects something. Updates live as form changes. --}}
        <div class="pt-4 mt-4 border-t border-line">
            <div class="flex items-center justify-between mb-1">
                <label class="block text-xs uppercase tracking-wider text-muted">/quickcreate command (paste in Discord)</label>
                <button type="button"
                        @click="navigator.clipboard.writeText(quickcreateCommand).then(() => { $refs.copyBtn.innerText = 'Copied!'; setTimeout(() => $refs.copyBtn.innerText = 'Copy', 2000); })"
                        x-ref="copyBtn"
                        class="text-xs px-2 py-1 rounded bg-line text-muted hover:text-ink">Copy</button>
            </div>
            <textarea readonly rows="4"
                      x-text="quickcreateCommand"
                      class="w-full bg-bg border border-line rounded px-3 py-2 text-xs font-mono text-muted focus:outline-none focus:border-accent select-all"></textarea>
            <p class="text-xs text-muted mt-1">
                If the API submission above fails for any reason, paste this command into any channel where the Raid-Helper bot is present.
            </p>
        </div>
    </form>
</div>
@endsection
