@php
    /**
     * Compact event-creation panel for a team page. Channel / template /
     * leader / announcement defaults come from the team preset; the
     * officer only fills in title, description, start time and duration.
     * Posts to the same /events endpoint as the full editor (hidden
     * inputs supply the preset values), so server-side validation,
     * Raid-Helper API call and DB upsert all reuse the existing flow.
     *
     * Required vars:
     *   $preset  array  config('raidhelper.teams.{slug}')
     *
     * Pill buttons on the start picker compute the next occurrence of
     * each configured raid day at the configured default time of day.
     */
    $tz = config('raidhelper.timezone');
    // Resolved preset already merges any officer-edited /admin/teams/schedule
    // override onto the config defaults; raid_time falls through to
    // raidhelper.default_time_of_day if neither layer set it.
    $raidTime = $preset['raid_time'] ?? config('raidhelper.default_time_of_day', '19:30');
    [$defH, $defM] = array_map('intval', explode(':', $raidTime . ':0'));
    $now = \Carbon\CarbonImmutable::now($tz);

    // Build the pill list once on the server so the rendered HTML is
    // stable; Alpine just writes the chosen value into starts_at.
    $pills = [];
    foreach ($preset['raid_days'] ?? [] as $iso) {
        $next = $now->copy();
        // Carbon's dayOfWeekIso: 1=Mon..7=Sun. Walk forward to the next
        // matching weekday (today counts only if it's still earlier than
        // the configured raid time).
        $candidate = $next->setTime($defH, $defM, 0);
        if ($candidate->dayOfWeekIso !== $iso || $candidate->lt($now)) {
            $advance = ($iso - $next->dayOfWeekIso + 7) % 7;
            $advance = $advance === 0 ? 7 : $advance;
            $candidate = $next->addDays($advance)->setTime($defH, $defM, 0);
        }
        $pills[] = [
            'label' => 'Next ' . $candidate->format('D'),
            'date' => $candidate->format('D d M H:i'),
            'value' => $candidate->format('Y-m-d\TH:i'),
        ];
    }
    // Sort soonest-first regardless of config order. ISO 8601 string
    // sort is correct ordering for the 'value' field.
    usort($pills, fn ($a, $b) => $a['value'] <=> $b['value']);
    $defaultStartsAt = $pills[0]['value'] ?? $now->copy()->addDay()->setTime($defH, $defM, 0)->format('Y-m-d\TH:i');

    $defaultAnnouncementChannelName = collect(config('raidhelper.channels', []))
        ->firstWhere('id', $preset['channel_id'] ?? null)['name'] ?? '';
    $defaultAnnouncements = config('raidhelper.default_announcements', []);

    // Template chooser. When the preset declares more than one
    // template_choices entry, surface a dropdown so the officer can
    // pick (e.g. socials toggle between accept/maybe/decline and
    // role+spec). Otherwise the panel stays simple and pins to the
    // single configured template_id via a hidden input.
    $defaultTemplateId = (string) ($preset['template_id'] ?? '9');
    $templateOptions = collect(config('raidhelper.templates', []))
        ->whereIn('id', $preset['template_choices'] ?? [])
        ->values()
        ->all();
    $showTemplatePicker = count($templateOptions) > 1;
@endphp

<section class="bg-panel border border-line rounded-lg overflow-hidden" x-data="{ explain: false }">
    <header class="px-4 py-3 border-b border-line flex items-center justify-between">
        <h2 class="text-sm font-semibold uppercase tracking-wider flex items-center gap-2">
            <span>Quick create</span>
            <x-explainer-toggle />
        </h2>
        <a href="{{ route('events.create') }}" class="text-xs text-muted hover:text-ink">Full editor &rarr;</a>
    </header>
    <x-explainer-panel title="Quick create">
        Posts a Raid-Helper event using this team's preset channel, template and reminders.
        Pick a night from the pills (or custom-set the date), give it a title, hit Create.
        For unusual events (different template, different channel, custom announcements)
        use the full editor.
    </x-explainer-panel>

    <form method="POST" action="{{ route('events.store') }}"
          x-data='{
              startsAt: @json(old("starts_at", $defaultStartsAt)),
              durationMinutes: @json((int) old("duration_minutes", 180)),
              setStart(v) { this.startsAt = v; },
          }'
          class="p-4 space-y-3">
        @csrf

        {{-- Hidden preset values. All required by the EventController
             validation; quick-create users never see them. --}}
        <input type="hidden" name="channel_id" value="{{ $preset['channel_id'] ?? '' }}">
        <input type="hidden" name="_channel_mode" value="preset">
        @unless ($showTemplatePicker)
            <input type="hidden" name="template_id" value="{{ $defaultTemplateId }}">
        @endunless
        <input type="hidden" name="leader_id" value="{{ auth()->user()->discord_id }}">
        <input type="hidden" name="duration_mode" value="duration">
        @foreach ($defaultAnnouncements as $i => $a)
            <input type="hidden" name="announcements[{{ $i }}][minutes]" value="{{ $a['minutes'] }}">
            <input type="hidden" name="announcements[{{ $i }}][message]" value="{{ $a['message'] }}">
            <input type="hidden" name="announcements[{{ $i }}][channel]" value="{{ $defaultAnnouncementChannelName }}">
        @endforeach

        @if (isset($errors) && $errors->any())
            <div class="p-2 rounded bg-rose-500/10 border border-rose-500/30 text-rose-300 text-xs">
                <ul class="list-disc list-inside">
                    @foreach ($errors->all() as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div>
            <label class="block text-xs uppercase tracking-wider text-muted mb-1">Title</label>
            <input type="text" name="title" required value="{{ old('title') }}"
                   placeholder="e.g. Manaforge Omega"
                   class="w-full bg-bg border border-line rounded px-3 py-2 text-sm focus:outline-none focus:border-accent">
        </div>

        @if ($showTemplatePicker)
            <div>
                <label class="block text-xs uppercase tracking-wider text-muted mb-1" for="qc_template_id">Template</label>
                <select id="qc_template_id" name="template_id" required
                        class="w-full bg-bg border border-line rounded px-3 py-2 text-sm focus:outline-none focus:border-accent">
                    @foreach ($templateOptions as $tpl)
                        <option value="{{ $tpl['id'] }}" @selected(old('template_id', $defaultTemplateId) === $tpl['id'])>
                            {{ $tpl['label'] }}
                        </option>
                    @endforeach
                </select>
            </div>
        @endif

        <div>
            <label class="block text-xs uppercase tracking-wider text-muted mb-1">Start</label>
            @if (! empty($pills))
                <div class="flex flex-wrap gap-1.5 mb-2">
                    @foreach ($pills as $p)
                        <button type="button"
                                @click="setStart(@js($p['value']))"
                                :class="startsAt === @js($p['value']) ? 'border-accent text-ink bg-accent/10' : 'border-line text-muted hover:text-ink'"
                                class="text-xs px-2.5 py-1 rounded border transition">
                            {{ $p['label'] }}
                            <span class="text-muted/70 ml-1">{{ $p['date'] }}</span>
                        </button>
                    @endforeach
                </div>
            @endif
            <input type="datetime-local" name="starts_at" required
                   x-model="startsAt"
                   class="w-full bg-bg border border-line rounded px-3 py-2 text-sm focus:outline-none focus:border-accent">
            <p class="text-xs text-muted mt-1">Time in <strong>{{ $tz }}</strong>.</p>
        </div>

        <div>
            <label class="block text-xs uppercase tracking-wider text-muted mb-1">Duration (minutes)</label>
            <input type="number" name="duration_minutes" required min="15" max="1440" step="15"
                   x-model.number="durationMinutes"
                   class="w-full bg-bg border border-line rounded px-3 py-2 text-sm focus:outline-none focus:border-accent">
        </div>

        <details class="text-xs">
            <summary class="text-muted hover:text-ink cursor-pointer">Description (optional)</summary>
            <textarea name="description" rows="3"
                      class="mt-2 w-full bg-bg border border-line rounded px-3 py-2 text-sm focus:outline-none focus:border-accent">{{ old('description') }}</textarea>
        </details>

        <button type="submit" class="w-full px-4 py-2 rounded bg-accent text-white text-sm font-medium hover:bg-accent/80">
            Create event
        </button>

        <p class="text-xs text-muted leading-relaxed">
            Posts to <code class="text-ink">#{{ $defaultAnnouncementChannelName ?: 'configured channel' }}</code>
            @if (! $showTemplatePicker)
                using template <code class="text-ink">{{ $defaultTemplateId }}</code>
            @endif
            with the standard reminder pings.
        </p>
    </form>
</section>
