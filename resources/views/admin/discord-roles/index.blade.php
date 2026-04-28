@extends('layouts.dashboard')

@section('title', 'Discord role mentions')

@section('content')
@php
    // Pre-shape the roles list for Alpine. Inlining this with @json(...
    // ->map(fn ...)) tickles Blade's bracket-pair tokenizer and throws
    // "Unclosed '[' does not match ')'", so we materialise it here.
    $rolesPayload = $roles
        ->map(fn ($r) => [
            'id' => $r->id,
            'name' => $r->name,
            'discord_id' => $r->discord_id,
            'delete' => false,
        ])
        ->values();
@endphp
    <div class="flex items-start justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-semibold">Discord role mentions</h1>
            <p class="text-sm text-muted mt-1">
                Roles auto-mentioned on every Raid-Helper event posted from this dashboard.
                Edit the role list, paste in each role's snowflake, then tick the box
                in each team's row to allocate it. Changes apply to the next event you create.
            </p>
        </div>
        <a href="{{ route('dashboard') }}" class="text-sm text-accent hover:underline shrink-0">Back to dashboard</a>
    </div>

    @if (session('status'))
        <div class="mb-4 p-3 rounded bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-sm">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 p-3 rounded bg-rose-500/10 border border-rose-500/30 text-rose-300 text-sm">
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.discord-roles.update') }}" class="space-y-6"
          x-data='{
              roles: @json($rolesPayload),
              newRoles: [],
              addNew() { this.newRoles.push({ name: "", discord_id: "" }); },
              removeNew(i) { this.newRoles.splice(i, 1); },
          }'>
        @csrf

        {{-- Section 1: Roles list. Existing rows render with their id
             as the form-field key so update() can route the values back
             to the right model; brand-new rows submit under new_roles[]
             and get inserted. --}}
        <section class="rounded-lg border border-line bg-panel p-5">
            <header class="mb-4">
                <h2 class="font-semibold">Pingable roles</h2>
                <p class="text-xs text-muted mt-1">
                    Every role you might want to mention in an event. The display name must match the
                    Discord role name <em>exactly</em> (case-sensitive) - that's what Raid-Helper uses to
                    resolve the @mention. The Discord ID column is optional and only kept for future
                    role-membership checks; mentions don't need it.
                </p>
            </header>

            <div class="space-y-2">
                <div class="hidden md:grid md:grid-cols-12 gap-2 text-xs uppercase tracking-wider text-muted px-1">
                    <div class="md:col-span-4">Display name (must match Discord)</div>
                    <div class="md:col-span-6">Discord role ID (optional)</div>
                    <div class="md:col-span-2">Delete</div>
                </div>

                <template x-for="(role, idx) in roles" :key="role.id">
                    <div class="grid grid-cols-1 md:grid-cols-12 gap-2 items-center py-1">
                        <div class="md:col-span-4">
                            <input type="text"
                                   :name="`roles[${role.id}][name]`"
                                   x-model="role.name"
                                   :disabled="role.delete"
                                   class="w-full bg-bg border border-line rounded px-3 py-2 text-sm focus:outline-none focus:border-accent disabled:opacity-50">
                        </div>
                        <div class="md:col-span-6">
                            <input type="text" inputmode="numeric"
                                   :name="`roles[${role.id}][discord_id]`"
                                   x-model="role.discord_id"
                                   :disabled="role.delete"
                                   placeholder="e.g. 1247279261434384415"
                                   class="w-full bg-bg border border-line rounded px-3 py-2 text-sm font-mono focus:outline-none focus:border-accent disabled:opacity-50">
                        </div>
                        <label class="md:col-span-2 text-xs text-rose-300/80 flex items-center gap-2">
                            <input type="hidden" :name="`roles[${role.id}][delete]`" :value="role.delete ? '1' : '0'">
                            <input type="checkbox" x-model="role.delete" class="accent-rose-400">
                            <span x-text="role.delete ? 'Will delete on save' : 'Tick to remove'"></span>
                        </label>
                    </div>
                </template>

                <template x-for="(row, idx) in newRoles" :key="`new-${idx}`">
                    <div class="grid grid-cols-1 md:grid-cols-12 gap-2 items-center py-1">
                        <div class="md:col-span-4">
                            <input type="text"
                                   :name="`new_roles[${idx}][name]`"
                                   x-model="row.name"
                                   placeholder="New role name"
                                   class="w-full bg-bg border border-line rounded px-3 py-2 text-sm focus:outline-none focus:border-accent">
                        </div>
                        <div class="md:col-span-6">
                            <input type="text" inputmode="numeric"
                                   :name="`new_roles[${idx}][discord_id]`"
                                   x-model="row.discord_id"
                                   placeholder="Discord role snowflake"
                                   class="w-full bg-bg border border-line rounded px-3 py-2 text-sm font-mono focus:outline-none focus:border-accent">
                        </div>
                        <button type="button" @click="removeNew(idx)"
                                class="md:col-span-2 text-xs text-muted hover:text-ink border border-line rounded px-2 py-1.5">
                            Cancel new row
                        </button>
                    </div>
                </template>
            </div>

            <button type="button" @click="addNew()"
                    class="mt-3 text-xs px-3 py-1.5 rounded border border-line text-muted hover:text-ink hover:border-muted">
                + Add role
            </button>
        </section>

        {{-- Section 2: Team allocations. One block per team slug from
             config('raidhelper.teams'). Each block has a checkbox per
             role (excluding rows the officer just marked for deletion);
             checked = include in this team's mention list. The checkbox
             order in the rendered HTML is the order we save into the
             pivot's `position` column. --}}
        <section class="rounded-lg border border-line bg-panel p-5">
            <header class="mb-4">
                <h2 class="font-semibold">Team allocations</h2>
                <p class="text-xs text-muted mt-1">
                    Tick the roles each team mentions on its events. The Heroic team's
                    events go to <code class="text-ink">#heroic-raid-signup</code>, Mythic's go
                    to <code class="text-ink">#mythic-raid-signup</code>, etc. Save once at the bottom.
                </p>
            </header>

            <div class="space-y-4">
                @foreach ($teams as $slug => $team)
                    @php $checked = $assignments[$slug] ?? []; @endphp
                    <div class="rounded border border-line bg-bg/50 p-4">
                        <div class="flex items-baseline justify-between gap-3 mb-3">
                            <h3 class="font-medium">
                                {{ $team['label'] ?? \Illuminate\Support\Str::title($slug) }}
                                <code class="text-xs text-muted ml-2">{{ $slug }}</code>
                            </h3>
                            @if (! empty($team['channel_id']))
                                <span class="text-xs text-muted font-mono">channel {{ $team['channel_id'] }}</span>
                            @endif
                        </div>
                        <div class="flex flex-wrap gap-1.5">
                            @forelse ($roles as $role)
                                @php $isOn = in_array($role->id, $checked, false); @endphp
                                <label class="cursor-pointer">
                                    <input type="checkbox"
                                           name="teams[{{ $slug }}][role_ids][]"
                                           value="{{ $role->id }}"
                                           class="peer sr-only"
                                           @checked($isOn)>
                                    <span class="inline-block px-3 py-1.5 rounded border text-sm transition
                                                 border-line text-muted hover:text-ink
                                                 peer-checked:border-accent peer-checked:bg-accent/15 peer-checked:text-ink">
                                        {{ '@' . $role->name }}
                                    </span>
                                </label>
                            @empty
                                <p class="text-xs text-muted">Add some roles above first.</p>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <div class="flex justify-end">
            <button type="submit" class="px-4 py-2 rounded bg-accent text-white text-sm font-medium hover:bg-accent/80">
                Save
            </button>
        </div>
    </form>
@endsection
