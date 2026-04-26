@extends('layouts.dashboard')

@section('title', 'Discord webhooks')

@section('content')
    <div class="flex items-start justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-semibold">Discord webhooks</h1>
            <p class="text-sm text-muted mt-1">
                One row per webhook URL. The senders (weekly digest, event
                announcer, event reminders) read this table to decide where to
                post. URLs are encrypted at rest.
            </p>
        </div>
        @if ($webhooks->isNotEmpty())
            <form method="POST" action="{{ route('admin.webhooks.test-all') }}" class="shrink-0">
                @csrf
                <button type="submit"
                        class="text-sm px-3 py-2 rounded border border-line bg-bg hover:bg-panel"
                        title="Send a test ping to every enabled webhook">
                    Test all
                </button>
            </form>
        @endif
    </div>

    @if (session('status'))
        <div class="mb-4 p-3 rounded border border-green-700 bg-green-900/30 text-sm text-green-200">
            {{ session('status') }}
        </div>
    @endif
    @if ($errors->any())
        <div class="mb-4 p-3 rounded border border-red-700 bg-red-900/30 text-sm text-red-200">
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="bg-panel border border-line rounded-lg overflow-hidden">
        <header class="px-5 py-3 border-b border-line">
            <h2 class="font-semibold">Configured webhooks</h2>
        </header>
        @if ($webhooks->isEmpty())
            <div class="p-8 text-center text-muted text-sm">
                No webhooks configured yet. Add one below.
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs text-muted uppercase tracking-wider">
                        <tr>
                            <th class="text-left px-5 py-2">Label</th>
                            <th class="text-left px-5 py-2">Purpose</th>
                            <th class="text-left px-5 py-2">Team</th>
                            <th class="text-left px-5 py-2">Enabled</th>
                            <th class="text-left px-5 py-2">Last posted</th>
                            <th class="text-left px-5 py-2">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($webhooks as $w)
                            <tr class="border-t border-line align-top">
                                <td class="px-5 py-2">
                                    <div class="text-ink">{{ $w->label }}</div>
                                </td>
                                <td class="px-5 py-2 text-muted text-xs">
                                    {{ \App\Models\DiscordWebhook::purposeLabel($w->purpose) }}
                                </td>
                                <td class="px-5 py-2 text-muted text-xs">
                                    {{ $w->team_slug ?? '(guild-wide)' }}
                                </td>
                                <td class="px-5 py-2">
                                    <form method="POST" action="{{ route('admin.webhooks.update', $w) }}" class="inline">
                                        @csrf @method('PUT')
                                        <input type="hidden" name="label" value="{{ $w->label }}">
                                        <input type="hidden" name="purpose" value="{{ $w->purpose }}">
                                        <input type="hidden" name="team_slug" value="{{ $w->team_slug }}">
                                        <input type="hidden" name="enabled" value="{{ $w->enabled ? 0 : 1 }}">
                                        <button type="submit"
                                                class="text-[10px] uppercase tracking-wider px-2 py-0.5 rounded border
                                                       {{ $w->enabled ? 'border-emerald-700/50 text-emerald-300' : 'border-line text-muted' }}">
                                            {{ $w->enabled ? 'On' : 'Off' }}
                                        </button>
                                    </form>
                                </td>
                                <td class="px-5 py-2 text-muted text-xs">
                                    {{ $w->last_posted_at?->diffForHumans() ?? 'never' }}
                                </td>
                                <td class="px-5 py-2 flex items-center gap-2">
                                    <form method="POST" action="{{ route('admin.webhooks.test', $w) }}">
                                        @csrf
                                        <button type="submit"
                                                class="text-xs px-2 py-1 rounded border border-line bg-bg hover:bg-panel">
                                            Test
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.webhooks.destroy', $w) }}"
                                          onsubmit="return confirm('Remove webhook &quot;{{ $w->label }}&quot;?');">
                                        @csrf @method('DELETE')
                                        <button type="submit"
                                                class="text-xs px-2 py-1 rounded border border-rose-700/50 text-rose-300 hover:bg-rose-950/30">
                                            Remove
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="bg-panel border border-line rounded-lg overflow-hidden mt-6">
        <header class="px-5 py-3 border-b border-line">
            <h2 class="font-semibold">Add webhook</h2>
            <p class="text-xs text-muted mt-1">
                Get the URL from Discord: channel settings &rsaquo; Integrations &rsaquo; Webhooks &rsaquo; New Webhook &rsaquo; Copy URL.
            </p>
        </header>
        <form method="POST" action="{{ route('admin.webhooks.store') }}" class="p-5 grid grid-cols-1 md:grid-cols-2 gap-4">
            @csrf
            <div>
                <label class="block text-xs uppercase tracking-wider text-muted mb-1">Label</label>
                <input type="text" name="label" required maxlength="255"
                       placeholder="Officer chat - weekly digest"
                       class="w-full bg-bg border border-line rounded px-2 py-1 text-sm">
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wider text-muted mb-1">Webhook URL</label>
                <input type="url" name="url" required
                       placeholder="https://discord.com/api/webhooks/..."
                       class="w-full bg-bg border border-line rounded px-2 py-1 text-sm font-mono">
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wider text-muted mb-1">Purpose</label>
                <select name="purpose" required
                        class="w-full bg-bg border border-line rounded px-2 py-1 text-sm">
                    @foreach ($purposes as $key => $info)
                        <option value="{{ $key }}">{{ $info['label'] }} - {{ $info['hint'] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wider text-muted mb-1">Team scope (optional)</label>
                <select name="team_slug"
                        class="w-full bg-bg border border-line rounded px-2 py-1 text-sm">
                    <option value="">(guild-wide)</option>
                    @foreach ($teamSlugs as $slug)
                        <option value="{{ $slug }}">{{ $slug }}</option>
                    @endforeach
                </select>
                <p class="text-[10px] text-muted mt-1">
                    Leave empty for senders that aren't team-specific (like the digest).
                </p>
            </div>
            <div class="md:col-span-2 flex items-center justify-between">
                <label class="text-xs text-muted flex items-center gap-1">
                    <input type="checkbox" name="enabled" value="1" checked
                           class="bg-bg border border-line rounded">
                    Enabled
                </label>
                <button type="submit"
                        class="text-sm px-4 py-2 rounded bg-accent text-white">
                    Add webhook
                </button>
            </div>
        </form>
    </section>
@endsection
