{{--
    Add-alt macro modal. Triggered by:
        $dispatch('open-add-alt', { id: memberId, name: 'Char-Realm', class: 'ROGUE' })

    Officer picks a target character (datalist autocomplete sourced
    from #roster-member-names rendered once at the bottom of the page)
    and the modal calls the server to validate the target + build the
    macro. Confirm logs MemberAction rows for both members so either's
    history surfaces the link.
--}}
<div x-data="addAltMacroModal()" x-cloak
     x-on:open-add-alt.window="open($event.detail)"
     x-show="visible"
     class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 px-4"
     x-transition.opacity>
    <div class="bg-panel border border-line rounded-lg max-w-2xl w-full max-h-[90vh] overflow-y-auto" @click.outside="close()">
        <header class="px-5 py-3 border-b border-line flex items-center justify-between">
            <h2 class="font-semibold text-ink">
                Link as alt
                <span class="text-muted text-sm font-normal" x-show="source.name">
                    : <span :class="'cls-' + (source.class || '').toUpperCase()" x-text="source.name"></span>
                </span>
            </h2>
            <button type="button" @click="close()" class="text-muted hover:text-ink text-xl leading-none">&times;</button>
        </header>

        <div class="px-5 py-4 space-y-4">
            <template x-if="error">
                <p class="text-sm text-rose-300" x-text="error"></p>
            </template>

            <template x-if="source.id">
                <div>
                    <p class="text-xs text-muted">
                        Pick the character to link <span :class="'cls-' + (source.class || '').toUpperCase()" x-text="source.name"></span>
                        with. GRM handles all four combinations (neither linked, either side linked, both linked) so this
                        works whether you're starting a new alt group or adding to an existing one. Copy the macro into
                        WoW and run it; the next GRM sync will reflect the link here.
                    </p>

                    <div class="mt-4">
                        <label class="text-xs text-muted block mb-1">Target character</label>
                        <input type="text"
                               x-model="targetName"
                               @input.debounce.300ms="lookup()"
                               list="roster-member-names"
                               placeholder="Start typing to filter (e.g. Sheday-Silvermoon)"
                               class="w-full bg-bg border border-line rounded px-2 py-1 text-sm font-mono">
                    </div>

                    <template x-if="target.id">
                        <div class="mt-3 text-xs text-muted">
                            Match: <span :class="'cls-' + (target.class || '').toUpperCase()" x-text="target.name"></span>
                        </div>
                    </template>

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
                                 x-text="macro || '(pick a target above)'"></pre>
                        </div>
                    </div>

                    <div class="mt-4">
                        <label class="text-xs text-muted block mb-1">Notes (optional, kept in audit log):</label>
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
function addAltMacroModal() {
    const csrf = document.querySelector('meta[name=csrf-token]')?.content ?? '';
    return {
        visible: false,
        confirming: false,
        error: null,
        source: {},
        target: {},
        targetName: '',
        macro: null,
        auditNotes: '',
        copied: false,

        open(detail) {
            this.reset();
            this.source = { id: detail.id, name: detail.name, class: detail.class || '' };
            this.visible = true;
        },

        // Server round-trip on every input (debounced) so we get
        // authoritative validation of the target name. Cheap query;
        // returns the matching member or 422 + error string.
        async lookup() {
            const name = this.targetName.trim();
            this.target = {};
            this.macro = null;
            this.error = null;
            if (!name || !this.source.id) return;

            try {
                const resp = await fetch('{{ route('roster.add-alt.preview') }}', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'},
                    body: JSON.stringify({source_member_id: this.source.id, target_name: name}),
                });
                const data = await resp.json();
                if (!resp.ok) {
                    // 422 is the "no such member" case; show the
                    // server's error string so the officer can fix.
                    this.error = data.error || 'Could not validate target';
                    return;
                }
                this.target = data.target || {};
                this.macro = data.macro || null;
            } catch (e) {
                this.error = 'Could not validate: ' + e.message;
            }
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
            if (!this.target.id) return;
            this.confirming = true;
            try {
                const resp = await fetch('{{ route('roster.add-alt.confirm') }}', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'},
                    body: JSON.stringify({
                        source_member_id: this.source.id,
                        target_member_id: this.target.id,
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
            this.source = {};
            this.target = {};
            this.targetName = '';
            this.macro = null;
            this.auditNotes = '';
            this.copied = false;
        },
    };
}
</script>
