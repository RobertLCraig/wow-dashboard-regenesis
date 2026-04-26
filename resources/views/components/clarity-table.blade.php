@props([
    'title' => null,
    'count' => null,
    'meta' => null,
    'empty' => 'No rows.',
    'isEmpty' => false,
    'searchable' => false,
    'searchPlaceholder' => 'Search...',
])

{{--
    Standard widget shell for tabular data. Renders:
    - a sectioned panel with header + (optional) search + (optional) meta
    - the consumer's <table class="...clarity-tabular"> in the slot
    - an empty-state when isEmpty=true

    The CSS in layouts/dashboard.blade.php targets table.clarity-tabular
    + td[data-label] to convert the table into a stacked-card list when
    body.mode-high-clarity is set. Same markup, two visual shapes; the
    Alpine sortableTable() filter still works because it operates on
    tr[data-row] regardless of how the row is laid out.

    The section's x-data always exposes both `search` (via sortableTable
    when searchable=true) and `explain` (false) so consumers can drop
    in <x-explainer-toggle /> inside the header slot and
    <x-explainer-panel /> in the explainer slot without setting up
    their own Alpine scope.

    Usage shape: an x-clarity-table with the standard header
    (title + optional search + optional explainer panel) wraps a
    consumer-supplied <table class="...clarity-tabular"> whose rows
    use data-row + data-label attrs. See widgets/recently-inactive
    for a complete example.
--}}

@php
    $xData = $searchable
        ? "{ ...sortableTable(), explain: false }"
        : "{ explain: false }";
@endphp

<section class="bg-panel border border-line rounded-lg overflow-hidden" x-data="{{ $xData }}">
    @if ($title || $searchable || $meta || $count !== null || isset($header) || isset($filters))
        <header class="px-4 py-3 border-b border-line flex items-center justify-between gap-3">
            @isset ($header)
                {{ $header }}
            @else
                <h2 class="text-sm font-semibold uppercase tracking-wider">{{ $title }}</h2>
            @endisset
            <div class="flex items-center gap-3 shrink-0 flex-wrap justify-end">
                @if (isset($filters) && ! $isEmpty)
                    {{ $filters }}
                @endif
                @if ($searchable && ! $isEmpty)
                    <input type="text" x-model="search"
                           placeholder="{{ $searchPlaceholder }}"
                           class="bg-bg border border-line rounded px-2 py-1 text-xs w-44 placeholder:text-muted">
                @endif
                @if ($meta)<span class="text-xs text-muted">{{ $meta }}</span>@endif
                @if ($count !== null)<span class="text-xs text-muted">{{ $count }}</span>@endif
            </div>
        </header>
    @endif

    @isset ($explainer){{ $explainer }}@endisset

    @if ($isEmpty)
        <div class="p-8 text-center text-muted text-sm">{!! $empty !!}</div>
    @else
        <div class="overflow-x-auto">
            {{ $slot }}
        </div>
    @endif
</section>
