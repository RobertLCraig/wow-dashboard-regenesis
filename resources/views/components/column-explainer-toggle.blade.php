{{--
    Per-column "?" toggle for table headers. Used in tables that opt into
    per-column explainers: the parent must define an Alpine scope with
    `openCol` (string|null). Clicking flips `openCol` between this column's
    key and null. Pair with a single explainer <tr> just below the thead
    that x-shows when openCol matches a key, and uses <template x-if> to
    render the body for each column.
--}}
@props(['col'])
<button type="button"
        @click.stop="openCol = openCol === '{{ $col }}' ? null : '{{ $col }}'"
        :aria-expanded="openCol === '{{ $col }}'"
        aria-label="Toggle column explanation"
        class="ml-1 w-4 h-4 inline-flex items-center justify-center rounded-full border border-line text-muted hover:text-ink hover:border-muted text-[10px] font-semibold leading-none cursor-pointer focus:outline-none focus:ring-1 focus:ring-accent transition-colors normal-case align-middle"
        :class="{ 'bg-accent/15 border-accent/60 text-accent hover:text-accent': openCol === '{{ $col }}' }">
    <span x-text="openCol === '{{ $col }}' ? '×' : '?'"></span>
</button>
