{{--
    Drop one of these somewhere on a page. Buttons elsewhere on the
    page open the modal by dispatching:
        $dispatch('open-set-main', { ids: [memberId] })

    Mirrors the kick-macro-modal flow but generates /run GRM.SetMain(...)
    lines. Single-character is the common case (officer correcting a
    drifted main); the modal also supports multi-character batches.
--}}
<div x-data="setMainMacroModal()" x-cloak
     x-on:open-set-main.window="open($event.detail.ids)"
     x-show="visible"
     class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 px-4"
     x-transition.opacity>
    <div class="bg-panel border border-line rounded-lg max-w-2xl w-full max-h-[90vh] overflow-y-auto" @click.outside="close()">
        <header class="px-5 py-3 border-b border-line flex items-center justify-between">
            <h2 class="font-semibold text-ink">
                Set as main
                <span class="text-muted text-sm font-normal" x-show="characters.length">
                    (<span x-text="selectedIds.length"></span> of <span x-text="characters.length"></span>)
                </span>
            </h2>
            <button type="button" @click="close()" class="text-muted hover:text-ink text-xl leading-none">&times;</button>
        </header>

        <div class="px-5 py-4 space-y-4">
            <template x-if="loading">
                <p class="text-sm text-muted">Loading...</p>
            </template>

            <template x-if="error">
                <p class="text-sm text-rose-300" x-text="error"></p>
            </template>

            <template x-if="!loading && characters.length">
                <div>
                    <p class="text-xs text-muted">
                        The dashboard can't change GRM data directly. Copy the macro into WoW
                        and run it; the next GRM sync will reflect the new main on the roster.
                    </p>

                    <div class="mt-3 space-y-1">
                        <template x-for="c in characters" :key="c.id">
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" :value="c.id" x-model.number="selectedIds"
                                       @change="rebuild()"
                                       class="bg-bg border border-line rounded">
                                <span :class="'cls-' + (c.class || '').toUpperCase()" x-text="c.name"></span>
                            </label>
                        </template>
                    </div>

                    <template x-if="skipped.length">
                        <div class="mt-3 text-xs text-amber-300/80">
                            <strong>Skipped:</strong>
                            <ul class="list-disc list-inside mt-1">
                                <template x-for="s in skipped" :key="s.id">
                                    <li><span x-text="s.name"></span> - <span x-text="s.reason"></span></li>
                                </template>
                            </ul>
                        </div>
                    </template>

                    <template x-if="oversized.length">
                        <div class="mt-3 text-xs text-rose-300">
                            <strong>Won't fit in any macro:</strong>
                            <span x-text="oversized.join(', ')"></span>
                        </div>
                    </template>

                    <div class="mt-4 space-y-2">
                        <template x-for="(macro, i) in macros" :key="i">
                            <div class="border border-line rounded">
                                <div class="px-3 py-2 border-b border-line flex items-center justify-between">
                                    <span class="text-xs uppercase tracking-wider text-muted">
                                        Macro <span x-text="i + 1"></span> of <span x-text="macros.length"></span>
                                        <span class="ml-2 text-muted/70" x-text="macro.length + ' / 255'"></span>
                                    </span>
                                    <button type="button" @click="copy(macro, i)"
                                            class="text-xs px-2 py-1 rounded border border-line bg-bg hover:bg-panel">
                                        <span x-text="copiedIdx === i ? 'Copied!' : 'Copy'"></span>
                                    </button>
                                </div>
                                <pre class="p-3 text-xs font-mono whitespace-pre-wrap break-all text-ink" x-text="macro"></pre>
                            </div>
                        </template>
                    </div>

                    <div class="mt-4">
                        <label class="text-xs text-muted block mb-1">Notes (optional, kept in audit log):</label>
                        <textarea x-model="notes" rows="2"
                                  class="w-full bg-bg border border-line rounded px-2 py-1 text-sm"></textarea>
                    </div>
                </div>
            </template>
        </div>

        <footer class="px-5 py-3 border-t border-line flex items-center justify-end gap-2">
            <button type="button" @click="close()"
                    class="text-sm px-3 py-1.5 rounded border border-line bg-bg hover:bg-panel">
                Cancel
            </button>
            <button type="button" @click="confirm()"
                    :disabled="loading || selectedIds.length === 0 || confirming"
                    class="text-sm px-3 py-1.5 rounded bg-accent text-white disabled:opacity-50 disabled:cursor-not-allowed">
                <span x-text="confirming ? 'Logging...' : 'I have run the macro'"></span>
            </button>
        </footer>
    </div>
</div>

<script>
function setMainMacroModal() {
    const csrf = document.querySelector('meta[name=csrf-token]')?.content ?? '';
    return {
        visible: false,
        loading: false,
        confirming: false,
        error: null,
        characters: [],
        skipped: [],
        oversized: [],
        macros: [],
        selectedIds: [],
        notes: '',
        copiedIdx: -1,

        async open(ids) {
            this.reset();
            this.visible = true;
            this.loading = true;
            try {
                const resp = await fetch('{{ route('roster.set-main.preview') }}', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'},
                    body: JSON.stringify({member_ids: ids}),
                });
                if (! resp.ok) throw new Error('HTTP ' + resp.status);
                const data = await resp.json();
                this.characters = data.characters || [];
                this.skipped = data.skipped || [];
                this.oversized = data.oversized || [];
                this.macros = data.macros || [];
                this.selectedIds = this.characters.map(c => c.id);
            } catch (e) {
                this.error = 'Could not load: ' + e.message;
            } finally {
                this.loading = false;
            }
        },

        // Mirror of SetMainMacroBuilder so deselecting in the UI doesn't
        // need a server round-trip. byteLength to count Lua-string bytes
        // accurately when names contain diacritics like Ñýxx-Draenor.
        rebuild() {
            const limit = 255;
            const selected = new Set(this.selectedIds);
            const lines = this.characters
                .filter(c => selected.has(c.id))
                .map(c => '/run GRM.SetMain("' + c.name.replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '")');
            const macros = [];
            let current = '';
            const byteLen = s => new TextEncoder().encode(s).length;
            for (const line of lines) {
                if (byteLen(line) > limit) continue;
                const candidate = current === '' ? line : current + '\n' + line;
                if (byteLen(candidate) > limit) {
                    macros.push(current);
                    current = line;
                } else {
                    current = candidate;
                }
            }
            if (current !== '') macros.push(current);
            this.macros = macros;
        },

        async copy(text, idx) {
            try {
                await navigator.clipboard.writeText(text);
                this.copiedIdx = idx;
                setTimeout(() => { if (this.copiedIdx === idx) this.copiedIdx = -1; }, 1500);
            } catch (e) {
                this.error = 'Clipboard blocked: ' + e.message;
            }
        },

        async confirm() {
            if (this.selectedIds.length === 0) return;
            this.confirming = true;
            try {
                const resp = await fetch('{{ route('roster.set-main.confirm') }}', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'},
                    body: JSON.stringify({member_ids: this.selectedIds, notes: this.notes}),
                });
                if (! resp.ok) throw new Error('HTTP ' + resp.status);
                this.close();
            } catch (e) {
                this.error = 'Could not log: ' + e.message;
            } finally {
                this.confirming = false;
            }
        },

        close() {
            this.visible = false;
            setTimeout(() => this.reset(), 200);
        },

        reset() {
            this.loading = false;
            this.confirming = false;
            this.error = null;
            this.characters = [];
            this.skipped = [];
            this.oversized = [];
            this.macros = [];
            this.selectedIds = [];
            this.notes = '';
            this.copiedIdx = -1;
        },
    };
}
</script>
