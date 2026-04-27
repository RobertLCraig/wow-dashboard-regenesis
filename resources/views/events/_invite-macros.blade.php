@php
    use App\Support\InviteMacroBuilder;

    /**
     * Build invite macros from the event's signups.
     *
     * Filter rules (config-free, opinionated):
     *   skip class_name in: Absence, Declined, Decline, Bench
     *   include everyone else (Healer / Ranged / Melee / Tank / real
     *   class names / Maybe / Tentative / Late). Raid leaders typically
     *   invite Maybe / Tentative / Late so they can be added on arrival.
     *
     * Names are run through InviteMacroBuilder::cleanName() to peel
     * parenthetical alts and slash/comma-separated nicknames; the raw
     * Discord name is kept alongside so the widget can flag anything
     * that doesn't survive cleanup.
     *
     * @var \App\Models\RaidEvent $event
     */
    $skipBuckets = ['Absence', 'Declined', 'Decline', 'Bench'];
    $signups = $event->signups()->orderBy('position')->get();

    $excluded = [];   // grouped by status bucket
    $rows = [];       // rows we'll generate /invite lines for
    foreach ($signups as $s) {
        $bucket = (string) ($s->class_name ?? '');
        if (in_array($bucket, $skipBuckets, true)) {
            $excluded[$bucket] = ($excluded[$bucket] ?? 0) + 1;
            continue;
        }
        $clean = InviteMacroBuilder::cleanName($s->name);
        $rows[] = [
            'raw' => (string) $s->name,
            'clean' => $clean,
            'bucket' => $bucket,
            'spec' => (string) ($s->spec_name ?? ''),
            'changed' => $clean !== null && $clean !== trim((string) $s->name),
        ];
    }

    $cleanNames = array_values(array_filter(array_map(fn ($r) => $r['clean'], $rows)));
    $build = InviteMacroBuilder::build($cleanNames);
    $macros = $build['macros'];
    $oversized = $build['oversized'];
    $renamed = array_values(array_filter($rows, fn ($r) => $r['changed']));
@endphp

<section class="bg-panel border border-line rounded-lg overflow-hidden mb-6"
         x-data="{ open: {{ count($macros) > 0 ? 'true' : 'false' }} }">
    <header class="px-4 py-3 border-b border-line flex items-center justify-between flex-wrap gap-2">
        <button type="button" @click="open = ! open"
                class="text-sm font-semibold uppercase tracking-wider hover:text-accent flex items-center gap-2">
            <span x-text="open ? '▾' : '▸'" class="text-muted text-xs"></span>
            Invite macros
        </button>
        <div class="text-xs text-muted flex items-center gap-2 flex-wrap">
            <span>{{ count($cleanNames) }} to invite</span>
            @foreach ($excluded as $bucket => $count)
                <span class="text-line">|</span>
                <span title="Excluded - {{ $bucket }}">{{ $bucket }} {{ $count }}</span>
            @endforeach
        </div>
    </header>

    <div x-show="open" x-cloak class="p-4 space-y-3">
        @if (empty($macros))
            <p class="text-sm text-muted italic">
                Nothing to invite - no signups in this event after filtering out
                {{ implode(' / ', $skipBuckets) }}.
            </p>
        @else
            <p class="text-xs text-muted">
                Paste each block into a WoW macro slot (255-byte cap per macro,
                so longer rosters spill across {{ count($macros) }} slots).
                Names from Discord may not exactly match WoW char names - edit
                the textarea before saving the macro if any look off.
            </p>

            @foreach ($macros as $i => $macro)
                <div x-data="{ copied: false }">
                    <div class="flex items-center justify-between text-xs text-muted mb-1">
                        <span>Macro {{ $i + 1 }} of {{ count($macros) }}</span>
                        <button type="button"
                                @click="navigator.clipboard.writeText($refs.txt{{ $i }}.value).then(() => { copied = true; setTimeout(() => copied = false, 1500); })"
                                class="text-xs px-2 py-1 rounded bg-line hover:text-ink">
                            <span x-show="! copied">Copy</span>
                            <span x-show="copied" x-cloak class="text-emerald-400">Copied!</span>
                        </button>
                    </div>
                    <textarea x-ref="txt{{ $i }}" rows="{{ min(8, substr_count($macro, "\n") + 1) }}"
                              class="w-full bg-bg border border-line rounded px-3 py-2 text-xs font-mono focus:outline-none focus:border-accent select-all">{{ $macro }}</textarea>
                </div>
            @endforeach

            @if (! empty($oversized))
                <div class="text-xs text-amber-400">
                    Skipped (name + /invite exceeds 255-byte macro limit):
                    {{ implode(', ', $oversized) }}
                </div>
            @endif

            @if (! empty($renamed))
                <details class="text-xs text-muted">
                    <summary class="cursor-pointer hover:text-ink">
                        {{ count($renamed) }} {{ \Illuminate\Support\Str::plural('name', count($renamed)) }} cleaned up before going into the macro
                    </summary>
                    <ul class="mt-2 space-y-0.5">
                        @foreach ($renamed as $r)
                            <li class="font-mono">
                                <span class="text-muted/70">{{ $r['raw'] }}</span>
                                <span class="text-line mx-1">&rarr;</span>
                                <span class="text-ink">{{ $r['clean'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif
        @endif
    </div>
</section>
