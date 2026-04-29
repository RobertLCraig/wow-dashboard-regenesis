{{--
    Edit a Member's Discord linkage. Triggered by:
        $dispatch('open-discord-link', {
            id: memberId,
            name: 'Char-Realm',
            class: 'PALADIN',
            discord_user_id: '12345' | null,
            discord_username: 'someone' | null,
        })

    Dashboard-only state: this saves directly to the members row, no
    macro generation, no GRM round-trip. PUT writes the link, DELETE
    clears it. Source is recorded as 'manual' on save so a later
    auto-resolver can tell hand-entered links apart from imported ones.
--}}
<div x-data="memberDiscordLinkModal()" x-cloak
     x-on:open-discord-link.window="open($event.detail)"
     x-show="visible"
     class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 px-4"
     x-transition.opacity>
    <div class="bg-panel border border-line rounded-lg max-w-xl w-full max-h-[90vh] overflow-y-auto" @click.outside="close()">
        <header class="px-5 py-3 border-b border-line flex items-center justify-between">
            <h2 class="font-semibold text-ink">
                Discord link
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
                <div class="space-y-4">
                    <p class="text-xs text-muted">
                        Connect this character to a Discord user. One Discord user can own many
                        characters (a player's main + all their alts), so it is fine for the same
                        ID and username to appear on several rows.
                    </p>

                    <div>
                        <label class="text-xs text-muted block mb-1">Discord user ID (snowflake)</label>
                        <input type="text" x-model="userId"
                               inputmode="numeric"
                               pattern="\d*"
                               placeholder="e.g. 123456789012345678"
                               class="w-full bg-bg border border-line rounded px-2 py-1 text-sm font-mono">
                        <p class="text-[11px] text-muted/80 mt-1">
                            17 to 20 digits. In Discord: enable Developer Mode, right-click the
                            user, choose Copy User ID. Leave blank to record only the username.
                        </p>
                    </div>

                    <div>
                        <label class="text-xs text-muted block mb-1">Discord username</label>
                        <input type="text" x-model="username"
                               maxlength="64"
                               placeholder="e.g. someone or someone#1234"
                               class="w-full bg-bg border border-line rounded px-2 py-1 text-sm font-mono">
                        <p class="text-[11px] text-muted/80 mt-1">
                            Display copy. Username-only entries can be auto-resolved to an ID later
                            by the bot.
                        </p>
                    </div>

                    <template x-if="link.source">
                        <p class="text-[11px] text-muted/80">
                            Currently linked via <span class="text-ink" x-text="link.source"></span>
                            <template x-if="link.linked_at">
                                <span> on <span x-text="formatDate(link.linked_at)"></span></span>
                            </template>.
                        </p>
                    </template>
                </div>
            </template>
        </div>

        <footer class="px-5 py-3 border-t border-line flex items-center justify-between gap-2">
            <button type="button" @click="clear()"
                    x-show="link.discord_user_id || link.discord_username"
                    :disabled="saving"
                    class="text-sm px-3 py-1.5 rounded border border-rose-700/50 text-rose-300 hover:bg-rose-950/30 disabled:opacity-50">
                Clear link
            </button>
            <span x-show="!(link.discord_user_id || link.discord_username)"></span>
            <div class="flex items-center gap-2">
                <button type="button" @click="close()"
                        class="text-sm px-3 py-1.5 rounded border border-line bg-bg hover:bg-panel">
                    Cancel
                </button>
                <button type="button" @click="save()"
                        :disabled="saving || (!userId.trim() && !username.trim())"
                        class="text-sm px-3 py-1.5 rounded bg-accent text-white disabled:opacity-50 disabled:cursor-not-allowed">
                    <span x-text="saving ? 'Saving...' : 'Save'"></span>
                </button>
            </div>
        </footer>
    </div>
</div>

<script>
function memberDiscordLinkModal() {
    const csrf = document.querySelector('meta[name=csrf-token]')?.content ?? '';
    return {
        visible: false,
        saving: false,
        error: null,
        character: {},
        userId: '',
        username: '',
        link: {},

        open(detail) {
            this.reset();
            this.character = { id: detail.id, name: detail.name, class: detail.class || '' };
            this.userId = detail.discord_user_id ?? '';
            this.username = detail.discord_username ?? '';
            this.link = {
                discord_user_id: detail.discord_user_id ?? null,
                discord_username: detail.discord_username ?? null,
                source: detail.discord_link_source ?? null,
                linked_at: detail.discord_linked_at ?? null,
            };
            this.visible = true;
        },

        async save() {
            const userId = this.userId.trim();
            const username = this.username.trim();
            if (!userId && !username) return;
            // Light client-side check so the user gets immediate feedback
            // for the obvious typo (pasted the username into the ID box).
            if (userId && !/^\d{17,20}$/.test(userId)) {
                this.error = 'Discord user ID must be 17 to 20 digits.';
                return;
            }
            this.saving = true;
            this.error = null;
            try {
                const resp = await fetch(`/roster/${this.character.id}/discord-link`, {
                    method: 'PUT',
                    headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'},
                    body: JSON.stringify({discord_user_id: userId || null, discord_username: username || null}),
                });
                if (! resp.ok) {
                    const data = await resp.json().catch(() => ({}));
                    throw new Error(data.message || ('HTTP ' + resp.status));
                }
                const data = await resp.json();
                this.link = data.link || {};
                this.close({reload: true});
            } catch (e) {
                this.error = 'Could not save: ' + e.message;
            } finally {
                this.saving = false;
            }
        },

        async clear() {
            if (!confirm('Clear the Discord link on this character?')) return;
            this.saving = true;
            this.error = null;
            try {
                const resp = await fetch(`/roster/${this.character.id}/discord-link`, {
                    method: 'DELETE',
                    headers: {'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'},
                });
                if (! resp.ok) throw new Error('HTTP ' + resp.status);
                this.close({reload: true});
            } catch (e) {
                this.error = 'Could not clear: ' + e.message;
            } finally {
                this.saving = false;
            }
        },

        close(opts) {
            this.visible = false;
            // Defer reset so the closing animation doesn't visibly empty the modal.
            setTimeout(() => this.reset(), 200);
            if (opts && opts.reload) {
                // The roster row caches the link state in the data-* attributes
                // it dispatched, so a reload is the cheapest way to reflect the
                // change everywhere on the page (button text, badge, etc).
                window.location.reload();
            }
        },

        formatDate(iso) {
            try {
                return new Date(iso).toLocaleDateString(undefined, {year: 'numeric', month: 'short', day: 'numeric'});
            } catch (e) {
                return iso;
            }
        },

        reset() {
            this.saving = false;
            this.error = null;
            this.character = {};
            this.userId = '';
            this.username = '';
            this.link = {};
        },
    };
}
</script>
