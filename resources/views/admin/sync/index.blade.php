@extends('layouts.dashboard')

@section('title', 'Data sync')

@push('head')
    @if ($autoRefresh)
        {{-- Cheap polling: meta-refresh while at least one source is
             mid-sync. Stops as soon as everything is done/failed/idle. --}}
        <meta http-equiv="refresh" content="5">
    @endif
@endpush

@section('content')
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">Data sync</h1>
            <p class="text-sm text-muted mt-1">
                One panel per data source. Background syncs return immediately;
                this page auto-refreshes while one is in progress.
            </p>
        </div>
        <a href="{{ route('dashboard') }}" class="text-sm text-accent hover:underline">Back to dashboard</a>
    </div>

    @if (session('status'))
        <div class="mt-4 p-4 rounded border border-emerald-600 bg-emerald-900/30 text-sm text-emerald-100 flex items-start gap-3">
            <span class="text-emerald-300 text-lg leading-none">&check;</span>
            <span>{{ session('status') }}</span>
        </div>
    @endif

    @if ($errors->any())
        <div class="mt-4 p-3 rounded border border-red-700 bg-red-900/30 text-sm text-red-200">
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-4">
        @foreach ($sources as $key => $src)
            @php
                $state = $src['state'];
                $status = $state['status'] ?? null;
                $running = in_array($status, ['queued', 'running'], true);
                $statusTone = match ($status) {
                    'running', 'queued' => 'border-amber-700/60 bg-amber-950/30 text-amber-200',
                    'done' => 'border-emerald-700/60 bg-emerald-950/30 text-emerald-200',
                    'failed' => 'border-red-700/60 bg-red-900/30 text-red-200',
                    default => 'border-line bg-bg/40 text-muted',
                };
                $startedAt = isset($state['started_at']) ? \Illuminate\Support\Carbon::parse($state['started_at']) : null;
                $finishedAt = isset($state['finished_at']) && $state['finished_at']
                    ? \Illuminate\Support\Carbon::parse($state['finished_at']) : null;
            @endphp

            <section class="rounded-lg border border-line bg-panel overflow-hidden">
                <header class="px-5 py-3 border-b border-line">
                    <h2 class="font-semibold">{{ $src['label'] }}</h2>
                    <p class="text-xs text-muted mt-1">{{ $src['description'] }}</p>
                </header>

                <div class="px-5 py-4 space-y-3 text-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-xs uppercase tracking-wider text-muted">Last sync</span>
                        <span class="text-ink text-right">
                            @if ($src['last_seen_at'])
                                {{ $src['last_seen_at']->diffForHumans() }}
                                @if ($src['last_summary'])
                                    <span class="text-muted">/</span>
                                    <span class="text-muted">{{ $src['last_summary'] }}</span>
                                @endif
                            @else
                                <span class="text-muted italic">none yet</span>
                            @endif
                        </span>
                    </div>

                    <div class="flex items-center justify-between">
                        <span class="text-xs uppercase tracking-wider text-muted">Cadence</span>
                        <span class="text-muted text-xs">{{ $src['cadence'] }}</span>
                    </div>

                    @if ($state)
                        @php
                            $stage = $state['stage'] ?? null;
                            $stageLabel = match ($stage) {
                                'parsing'     => 'Parsing SavedVariables.lua...',
                                'saving'      => 'Saving snapshot to disk...',
                                'normalizing' => 'Writing members, alt groups and log events...',
                                'diffing'     => 'Detecting joins, level-ups and inactivity...',
                                'duplicate'   => 'Already imported (matches an earlier snapshot).',
                                'done'        => 'Imported.',
                                default       => null,
                            };
                            $summary = is_array($state['summary'] ?? null) ? $state['summary'] : [];
                            $labels = [
                                'file_name' => 'file',
                                'file_size_kb' => 'size (KB)',
                                'parse_ms' => 'parse (ms)',
                                'grm_version' => 'GRM version',
                                'current_rows' => 'current rows in file',
                                'former_rows' => 'former rows in file',
                                'file_alt_groups' => 'alt groups in file',
                                'log_rows_in_file' => 'log rows in file',
                                'snapshot_id' => 'snapshot',
                                'was_duplicate' => 'duplicate',
                                'members_ingested' => 'members ingested',
                                'log_events_added' => 'new log events',
                                'alt_groups_ingested' => 'alt groups ingested',
                                'change_events_emitted' => 'change events emitted',
                            ];
                            // Render known GRM keys first in a deliberate order,
                            // then any other keys (e.g. RIO's matched/missing,
                            // wowaudit member counts) using their raw name as
                            // the label. Skips internal arrays like the diff
                            // event breakdown which is rendered separately.
                            $orderedKeys = array_keys($labels);
                            foreach (array_keys($summary) as $k) {
                                if (! in_array($k, $orderedKeys, true) && $k !== 'change_events_breakdown') {
                                    $orderedKeys[] = $k;
                                    $labels[$k] ??= $k;
                                }
                            }
                            $breakdown = is_array($summary['change_events_breakdown'] ?? null)
                                ? $summary['change_events_breakdown'] : [];
                        @endphp
                        <div class="rounded border {{ $statusTone }} p-3 text-xs space-y-2">
                            <div class="flex items-center justify-between gap-3">
                                <div class="flex items-center gap-2">
                                    <strong class="uppercase tracking-wider">{{ $status }}</strong>
                                    @if ($stageLabel)
                                        <span class="text-[11px] opacity-80">{{ $stageLabel }}</span>
                                    @endif
                                </div>
                                @if ($startedAt)
                                    <span class="text-muted text-[11px] whitespace-nowrap">started {{ $startedAt->diffForHumans() }}</span>
                                @endif
                            </div>

                            @if ($status === 'failed' && ! empty($state['error']))
                                <div class="font-mono text-[11px] break-all">{{ $state['error'] }}</div>
                            @endif

                            @if (! empty($summary))
                                <div class="text-muted font-mono text-[11px] flex flex-wrap gap-x-4 gap-y-1">
                                    @foreach ($orderedKeys as $k)
                                        @if (array_key_exists($k, $summary))
                                            @php $v = $summary[$k]; @endphp
                                            @if ($v !== null && ! is_array($v))
                                                <span>{{ $labels[$k] }}: {{ is_bool($v) ? ($v ? 'yes' : 'no') : $v }}</span>
                                            @endif
                                        @endif
                                    @endforeach
                                    @if ($finishedAt && $startedAt)
                                        <span>took: {{ max(0, $startedAt->diffInSeconds($finishedAt)) }}s</span>
                                    @endif
                                </div>
                            @endif

                            @if (! empty($breakdown))
                                <div class="text-muted font-mono text-[11px] pt-1 border-t border-current/20 flex flex-wrap gap-x-3 gap-y-1">
                                    <span class="opacity-70">events:</span>
                                    @foreach ($breakdown as $type => $count)
                                        @if ($count > 0)
                                            <span>{{ strtolower($type) }}: {{ $count }}</span>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif

                    <div class="flex items-center gap-3 pt-1">
                        @php
                            $syncRoute = match ($key) {
                                'raiderio' => 'admin.raiderio.sync',
                                'wowaudit' => 'admin.wowaudit.sync',
                                'wcl'      => 'admin.wcl.sync',
                                'blizzard' => 'admin.blizzard.sync',
                                default => null,
                            };
                        @endphp

                        @if ($src['has_button'] && $syncRoute)
                            <form method="POST" action="{{ route($syncRoute) }}">
                                @csrf
                                <button type="submit"
                                        @disabled($running)
                                        class="text-sm px-3 py-2 rounded border border-line bg-bg hover:bg-panel disabled:opacity-50 disabled:cursor-not-allowed">
                                    {{ $running ? 'Syncing...' : 'Sync now' }}
                                </button>
                            </form>
                        @endif

                        @if ($src['has_upload'])
                            <form method="POST" action="{{ route('admin.sync.grm.upload') }}"
                                  enctype="multipart/form-data"
                                  class="flex items-center gap-2"
                                  x-data="{ uploading: false, hasFile: false }"
                                  @submit="uploading = true">
                                @csrf
                                {{-- Don't bind :disabled on the file input. Disabled
                                     inputs are excluded from form submission, so
                                     toggling it during @submit drops the file
                                     and Laravel sees grm_file as missing. --}}
                                <input type="file" name="grm_file" accept=".lua,.txt"
                                       required
                                       @change="hasFile = $event.target.files.length > 0"
                                       class="text-xs text-muted file:bg-bg file:border file:border-line file:rounded file:text-ink file:text-xs file:px-2 file:py-1 file:mr-2 file:cursor-pointer">
                                <button type="submit"
                                        :disabled="uploading || ! hasFile || {{ $running ? 'true' : 'false' }}"
                                        class="text-sm px-3 py-2 rounded border border-line bg-bg hover:bg-panel disabled:opacity-50 disabled:cursor-not-allowed">
                                    <span x-show="! uploading">Upload</span>
                                    <span x-show="uploading" x-cloak>Uploading + processing...</span>
                                </button>
                            </form>
                        @endif
                    </div>

                    @if ($src['has_upload'])
                        <details class="text-xs mt-2" x-data="grmFileHelper()">
                            <summary class="text-muted hover:text-ink cursor-pointer select-none">
                                Where is Guild_Roster_Manager.lua on disk?
                            </summary>
                            <div class="mt-2 space-y-2 pl-3 border-l border-line">
                                <p class="text-muted">
                                    Browsers can't open File Explorer for you, but you can
                                    paste any of these into Explorer's address bar (or hit
                                    Win+R, paste, Enter) to jump to your SavedVariables
                                    folder. Click a row to copy.
                                </p>

                                <label class="flex items-center gap-2">
                                    <span class="text-muted whitespace-nowrap">My WoW account folder:</span>
                                    <input type="text" x-model="account" @input="saveAcct()"
                                           placeholder="WoW1"
                                           class="px-2 py-1 rounded border border-line bg-bg text-ink text-xs w-44">
                                    <span class="text-muted text-[11px]">(default: WoW1; saved in this browser)</span>
                                </label>
                                <p class="text-muted text-[11px] -mt-1">
                                    This is the folder name under <code class="text-ink">WTF\Account\</code>,
                                    not your Battle.net login. Most people see <code class="text-ink">WoW1</code>;
                                    custom-named WoW accounts (set in Battle.net Account Management) show up
                                    as that name in uppercase. If unsure, open
                                    <code class="text-ink">...\WTF\Account\</code> and you'll see the folder.
                                </p>

                                <ul class="space-y-1 font-mono">
                                    <template x-for="p in paths" :key="p">
                                        <li>
                                            <button type="button" @click="copy(p)"
                                                    class="text-left w-full px-2 py-1 rounded border border-line bg-bg/50 hover:bg-bg flex items-center justify-between gap-2 text-[11px]">
                                                <span x-text="fillAcct(p)" class="break-all"></span>
                                                <span x-show="copiedPath === p" x-cloak class="text-emerald-400 whitespace-nowrap">copied!</span>
                                                <span x-show="copiedPath !== p" class="text-muted whitespace-nowrap">copy</span>
                                            </button>
                                        </li>
                                    </template>
                                </ul>

                                <p class="text-muted">
                                    The file you want is named
                                    <code class="text-ink">Guild_Roster_Manager.lua</code>.
                                    If GRM hasn't run since your last guild change, log into
                                    a guild character and /reload first so the file is fresh.
                                </p>
                            </div>
                        </details>
                    @endif
                </div>
            </section>
        @endforeach
    </div>

    <div class="mt-8 text-xs text-muted">
        Background syncs run inside the same PHP process that handled the click,
        flushing the response first so the browser doesn't wait. Hostinger's
        per-request 30-60s ceiling still applies; if a sync needs to run longer
        than that, set up a queue worker
        (<code class="text-ink">php artisan queue:work --stop-when-empty --max-time=55</code>)
        triggered from the same cron that runs <code class="text-ink">schedule:run</code>.
    </div>

    <script>
        // Helper for the GRM "where is this file?" expander. Shows a list of
        // likely SavedVariables paths with the user's WoW account name spliced
        // in, click-to-copy, since browsers cannot open File Explorer for us.
        // Account name persists per-browser via localStorage.
        function grmFileHelper() {
            return {
                account: localStorage.getItem('wow_account_name') || '',
                copiedPath: null,
                paths: [
                    'C:\\Program Files (x86)\\World of Warcraft\\_retail_\\WTF\\Account\\<ACCT>\\SavedVariables',
                    'C:\\Program Files\\World of Warcraft\\_retail_\\WTF\\Account\\<ACCT>\\SavedVariables',
                    'C:\\Games\\World of Warcraft\\_retail_\\WTF\\Account\\<ACCT>\\SavedVariables',
                    'D:\\World of Warcraft\\_retail_\\WTF\\Account\\<ACCT>\\SavedVariables',
                    'D:\\Games\\World of Warcraft\\_retail_\\WTF\\Account\\<ACCT>\\SavedVariables',
                ],
                fillAcct(p) {
                    // Preserve casing as the user typed it. WoW1/WoW2/etc. are
                    // mixed-case by default; renamed accounts (like DJINNWRAITH)
                    // appear all-caps. Either way, what they typed is correct.
                    const acct = (this.account || '').trim();
                    return p.replace('<ACCT>', acct || 'WoW1');
                },
                saveAcct() {
                    localStorage.setItem('wow_account_name', (this.account || '').trim());
                },
                async copy(p) {
                    const text = this.fillAcct(p);
                    try {
                        await navigator.clipboard.writeText(text);
                        this.copiedPath = p;
                        setTimeout(() => { if (this.copiedPath === p) this.copiedPath = null; }, 2500);
                    } catch (_) {
                        // Clipboard API blocked (insecure context, permissions).
                        // Fall back to a prompt the user can manually copy from.
                        window.prompt('Copy this path:', text);
                    }
                },
            };
        }
    </script>
@endsection
