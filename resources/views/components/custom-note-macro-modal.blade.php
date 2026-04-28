{{--
    Edit GRM custom note. Triggered by:
        $dispatch('open-custom-note', { id: memberId, name: 'Char-Realm', currentNote: '...' })

    The modal lets the officer type a new note (max 150 chars per GRM)
    and toggle replace vs append. Macro is built client-side as they
    type so they can see the exact /run line that's about to be
    pasted into WoW; server preview is also called once on open to
    fetch the current GRM custom note for context.
--}}
<div x-data="customNoteMacroModal()" x-cloak
     x-on:open-custom-note.window="open($event.detail)"
     x-show="visible"
     class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 px-4"
     x-transition.opacity>
    <div class="bg-panel border border-line rounded-lg max-w-2xl w-full max-h-[90vh] overflow-y-auto" @click.outside="close()">
        <header class="px-5 py-3 border-b border-line flex items-center justify-between">
            <h2 class="font-semibold text-ink">
                Edit GRM custom note
                <span class="text-muted text-sm font-normal" x-show="character.name">
                    : <span :class="'cls-' + (character.class || '').toUpperCase()" x-text="character.name"></span>
                </span>
            </h2>
            <button type="button" @click="close()" class="text-muted hover:text-ink text-xl leading-none">&times;</button>
        </header>

        <div class="px-5 py-4 space-y-4">
            <template x-if="error">
                <p class="text-sm text-rose-300" x-text="error"></p>
            </template>

            <template x-if="character.id">
                <div>
                    <p class="text-xs text-muted">
                        This edits GRM's own custom note (not WoW's Public or Officer note).
                        Copy the macro into WoW and run it; the next GRM sync will reflect
                        the change here.
                    </p>

                    <div class="mt-3">
                        <label class="text-xs text-muted block mb-1">Current GRM note:</label>
                        <pre class="text-xs bg-bg border border-line rounded px-2 py-1 whitespace-pre-wrap break-words text-muted"
                             x-text="character.current_note || '(empty)'"></pre>
                    </div>

                    <div class="mt-4">
                        <label class="text-xs text-muted block mb-1">
                            New note text
                            <span class="ml-2 text-muted/70" x-text="note.length + ' / 150'"></span>
                        </label>
                        <textarea x-model="note"
                                  rows="3"
                                  maxlength="150"
                                  class="w-full bg-bg border border-line rounded px-2 py-1 text-sm font-mono"
                                  placeholder="e.g. Tank, off-spec heals; available Wed/Thu raid nights"></textarea>
                    </div>

                    <div class="mt-3 flex items-center gap-4 text-sm">
                        <label class="flex items-center gap-2">
                            <input type="radio" x-model="replaceMode" value="replace" class="bg-bg border border-line">
                            <span>Replace existing</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="radio" x-model="replaceMode" value="append" class="bg-bg border border-line">
                            <span>Append to existing</span>
                        </label>
                    </div>

                    <div class="mt-4">
                        <div class="border border-line rounded">
                            <div class="px-3 py-2 border-b border-line flex items-center justify-between">
                                <span class="text-xs uppercase tracking-wider text-muted">
                                    Macro
                                    <span class="ml-2 text-muted/70" x-text="(macro?.length || 0) + ' / 255'"></span>
                                </span>
                                <button type="button" @click="copy()"
                                        :disabled="!macro"
                                        class="text-xs px-2 py-1 rounded border border-line bg-bg hover:bg-panel disabled:opacity-50">
                                    <span x-text="copied ? 'Copied!' : 'Copy'"></span>
                                </button>
                            </div>
                            <pre class="p-3 text-xs font-mono whitespace-pre-wrap break-all text-ink"
                                 x-text="macro || '(type a note above)'"></pre>
                        </div>
                    </div>

                    <div class="mt-4">
                        <label class="text-xs text-muted block mb-1">Audit-log note (optional, not sent to GRM):</label>
                        <textarea x-model="auditNotes" rows="2"
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
                    :disabled="!macro || confirming"
                    class="text-sm px-3 py-1.5 rounded bg-accent text-white disabled:opacity-50 disabled:cursor-not-allowed">
                <span x-text="confirming ? 'Logging...' : 'I have run the macro'"></span>
            </button>
        </footer>
    </div>
</div>

<script>
function customNoteMacroModal() {
    const csrf = document.querySelector('meta[name=csrf-token]')?.content ?? '';
    return {
        visible: false,
        confirming: false,
        error: null,
        character: {},
        note: '',
        replaceMode: 'replace',
        auditNotes: '',
        copied: false,

        get macro() {
            return this.buildMacro();
        },

        async open(detail) {
            this.reset();
            this.character = { id: detail.id, name: detail.name, class: detail.class || '', current_note: '' };
            this.visible = true;
            // Server fetch only to enrich with current_note. We don't
            // wait on it to render the textarea so the modal feels
            // immediate; the current note populates when it returns.
            try {
                const resp = await fetch('{{ route('roster.custom-note.preview') }}', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'},
                    body: JSON.stringify({member_id: detail.id, note: ' ', replace: true}),
                });
                if (resp.ok) {
                    const data = await resp.json();
                    if (data.character) {
                        this.character = { ...this.character, ...data.character };
                    }
                }
            } catch (e) {
                // Fetch failure isn't fatal; the modal still works,
                // it just won't show the current note.
            }
        },

        // Mirror of CustomNoteMacroBuilder so the macro updates live
        // as the officer types. Lua escapes for ", \, and newlines.
        buildMacro() {
            const note = this.note.trim();
            if (!note || !this.character.name) return null;
            if (note.length > 150) return null;
            const escName = this.character.name
                .replace(/\\/g, '\\\\').replace(/"/g, '\\"');
            const escNote = note
                .replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\r?\n/g, '\\n');
            const replace = this.replaceMode === 'replace' ? 'true' : 'false';
            const line = `/run GRM_API.EditCustomNote("${escName}","${escNote}",${replace},false)`;
            return new TextEncoder().encode(line).length > 255 ? null : line;
        },

        async copy() {
            if (!this.macro) return;
            try {
                await navigator.clipboard.writeText(this.macro);
                this.copied = true;
                setTimeout(() => { this.copied = false; }, 1500);
            } catch (e) {
                this.error = 'Clipboard blocked: ' + e.message;
            }
        },

        async confirm() {
            if (!this.macro) return;
            this.confirming = true;
            try {
                const resp = await fetch('{{ route('roster.custom-note.confirm') }}', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'},
                    body: JSON.stringify({
                        member_id: this.character.id,
                        note: this.note,
                        replace: this.replaceMode === 'replace',
                        notes: this.auditNotes,
                    }),
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
            this.confirming = false;
            this.error = null;
            this.character = {};
            this.note = '';
            this.replaceMode = 'replace';
            this.auditNotes = '';
            this.copied = false;
        },
    };
}
</script>
