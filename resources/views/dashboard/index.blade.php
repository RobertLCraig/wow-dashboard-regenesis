@extends('layouts.dashboard')

@section('title', 'General')

@push('head')
    {{-- Sortable.js, only used in dashboard layout edit mode. ~10kb
         minified; loaded once from CDN, same posture as Tailwind /
         Chart.js / Alpine. --}}
    <script defer src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
    <script>
        function dashboardEditor(saveUrl) {
            return {
                editing: false,
                sortable: null,
                saveUrl: saveUrl,
                enterEdit() {
                    this.editing = true;
                    // Wait for x-show to update DOM before initialising
                    // Sortable, otherwise the handle isn't visible yet.
                    this.$nextTick(() => {
                        if (typeof Sortable === 'undefined') return;
                        this.sortable = new Sortable(this.$refs.grid, {
                            handle: '.js-drag-handle',
                            animation: 150,
                            ghostClass: 'opacity-30',
                            // Auto-scroll the window while dragging so
                            // long dashboards can be reordered without
                            // dropping the drag to scroll manually.
                            // forceAutoScrollFallback: true forces the
                            // built-in fallback even where native
                            // touch-based auto-scroll is available, so
                            // tablet + desktop behave the same.
                            scroll: true,
                            forceAutoScrollFallback: true,
                            scrollSensitivity: 80,
                            scrollSpeed: 20,
                            bubbleScroll: true,
                        });
                    });
                },
                cancel() {
                    // Easiest way to revert if the user dragged things
                    // around in edit mode and changed their mind.
                    window.location.reload();
                },
                async save() {
                    const keys = Array.from(this.$refs.grid.children)
                        .filter(el => el.hasAttribute('data-widget-key'))
                        .map(el => el.getAttribute('data-widget-key'));
                    if (! keys.length) {
                        alert('Could not read widget order from the grid; layout NOT saved.');
                        return;
                    }
                    const ok = await this.postLayout({ layout: keys });
                    if (ok) window.location.reload();
                },
                async resetToDefault() {
                    const ok = await this.postLayout({ reset: 1 });
                    if (ok) window.location.reload();
                },
                async postLayout(payload) {
                    if (! this.saveUrl) {
                        alert('Layout save URL not set on the dashboard editor.');
                        return false;
                    }
                    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                    if (! csrf) {
                        alert('CSRF token meta tag missing from the page; layout NOT saved.');
                        return false;
                    }
                    const body = new FormData();
                    if (payload.reset) body.append('reset', '1');
                    if (payload.layout) {
                        payload.layout.forEach(k => body.append('layout[]', k));
                    }
                    body.append('_token', csrf);
                    try {
                        const response = await fetch(this.saveUrl, {
                            method: 'POST',
                            body,
                            headers: {
                                'X-CSRF-TOKEN': csrf,
                                'X-Requested-With': 'XMLHttpRequest',
                                'Accept': 'application/json, text/html',
                            },
                            credentials: 'same-origin',
                        });
                        if (! response.ok) {
                            console.error('Layout save HTTP error', response.status);
                            alert('Layout save failed (HTTP ' + response.status + '). See console for details.');
                            return false;
                        }
                        return true;
                    } catch (err) {
                        console.error('Layout save network error', err);
                        alert('Layout save failed: ' + err.message);
                        return false;
                    }
                },
                // Click / keyboard reorder, runs against the same DOM
                // Sortable does, so Save reads the new order either way.
                // After the move we scroll the widget into view so the
                // user follows the action visually rather than guessing
                // whether the click did anything.
                moveUp(el) {
                    const prev = el.previousElementSibling;
                    if (! prev) return;
                    el.parentNode.insertBefore(el, prev);
                    el.scrollIntoView({ block: 'center', behavior: 'auto' });
                },
                moveDown(el) {
                    const next = el.nextElementSibling;
                    if (! next) return;
                    el.parentNode.insertBefore(next, el);
                    el.scrollIntoView({ block: 'center', behavior: 'auto' });
                },
            };
        }
    </script>
@endpush

@section('content')
    <div x-data="dashboardEditor('{{ route('preferences.dashboard-layout') }}')">
        <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
            <h1 class="text-xl font-semibold">General Guild Management</h1>

            <div class="flex items-center gap-2">
                <button type="button"
                        x-show="!editing"
                        @click="enterEdit()"
                        class="text-xs px-3 py-1.5 rounded border border-line bg-bg hover:bg-panel">
                    Edit layout
                </button>
                <template x-if="editing">
                    <div class="flex items-center gap-2">
                        <button type="button" @click="save()"
                                class="text-xs px-3 py-1.5 rounded bg-accent text-white font-medium hover:bg-accent/80">
                            Save layout
                        </button>
                        <button type="button" @click="resetToDefault()"
                                class="text-xs px-3 py-1.5 rounded border border-line bg-bg hover:bg-panel">
                            Reset to default
                        </button>
                        <button type="button" @click="cancel()"
                                class="text-xs px-3 py-1.5 rounded border border-line text-muted hover:text-ink">
                            Cancel
                        </button>
                    </div>
                </template>
            </div>
        </div>

        <div x-show="editing" x-cloak
             class="mb-4 px-4 py-3 rounded border border-accent/40 bg-accent/10 text-sm text-ink">
            <strong class="font-semibold">Editing layout.</strong>
            Drag a widget by its <code class="font-mono text-xs">⋮⋮</code> handle, or use the
            <span class="inline-flex items-center w-5 h-5 rounded border border-line text-[11px] font-mono align-middle">↑</span>
            <span class="inline-flex items-center w-5 h-5 rounded border border-line text-[11px] font-mono align-middle">↓</span>
            buttons to reorder. Click <em class="not-italic font-medium">Save layout</em> when you're happy, or
            <em class="not-italic font-medium">Reset to default</em> to restore the project's order.
        </div>

        {{-- The widget grid. data-widget-key on each wrapper is what
             Sortable + the keyboard buttons read to capture order. --}}
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6" x-ref="grid">
            @foreach ($widgets as $widget)
                <div class="{{ $widget['col_span'] ?: '' }} relative"
                     data-widget-key="{{ $widget['key'] }}">
                    {{-- Edit-mode chrome. Absolutely positioned so it
                         doesn't shift the widget when toggled. --}}
                    <div x-show="editing" x-cloak
                         class="absolute -top-2 left-2 right-2 z-20 flex items-center justify-between bg-panel border border-accent/60 rounded-md px-2 py-1 shadow">
                        <div class="flex items-center gap-2 text-xs text-ink">
                            <span class="js-drag-handle cursor-grab text-muted hover:text-ink select-none" title="Drag to reorder" aria-hidden="true">⋮⋮</span>
                            <span class="font-medium">{{ $widget['title'] }}</span>
                        </div>
                        <div class="flex items-center gap-1">
                            <button type="button" @click="moveUp($el.closest('[data-widget-key]'))"
                                    class="w-6 h-6 inline-flex items-center justify-center rounded border border-line text-muted hover:text-ink text-xs"
                                    aria-label="Move up">↑</button>
                            <button type="button" @click="moveDown($el.closest('[data-widget-key]'))"
                                    class="w-6 h-6 inline-flex items-center justify-center rounded border border-line text-muted hover:text-ink text-xs"
                                    aria-label="Move down">↓</button>
                        </div>
                    </div>

                    {{-- Dim the widget content while editing so the
                         chrome reads as the active layer. --}}
                    <div :class="editing ? 'opacity-60 pointer-events-none' : ''">
                        @include($widget['partial'], [$widget['data_key'] => $widgetData[$widget['data_key']] ?? null])
                    </div>
                </div>
            @endforeach
        </div>

        @if (! $lastSnapshot)
            <div class="mt-8 p-4 rounded border border-line bg-panel text-sm text-muted">
                No data ingested yet. Run <code class="text-ink">tools/grm-sync/grm-sync.ps1 -Force</code>
                on your PC after the next time you log in to WoW.
            </div>
        @endif
    </div>
@endsection
