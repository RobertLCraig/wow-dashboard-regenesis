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

    Drop-in usage:

        <x-clarity-table title="Recently inactive"
                         :is-empty="$rows->isEmpty()"
                         searchable
                         search-placeholder="Search name or rank..."
                         empty="No members inactive over 30 days.">
            <table class="w-full text-sm clarity-tabular">
                <thead>...</thead>
                <tbody>
                    @foreach ($rows as $r)
                        <tr data-row data-card-title="{{ $r->name }}">
                            <td data-label="Name" data-sort-key="name">...</td>
                            ...
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-clarity-table>

    For a custom header (e.g. with explainer toggle, tabs), pass a
    `header` slot:

        <x-slot:header>
            <h2>...</h2>
            <x-explainer-toggle />
        </x-slot:header>
--}}

<section class="bg-panel border border-line rounded-lg overflow-hidden"
         @if ($searchable) x-data="sortableTable()" @endif>
    @if ($title || $searchable || $meta || $count !== null || isset($header))
        <header class="px-4 py-3 border-b border-line flex items-center justify-between gap-3">
            @isset ($header)
                {{ $header }}
            @else
                <h2 class="text-sm font-semibold uppercase tracking-wider">{{ $title }}</h2>
            @endisset
            <div class="flex items-center gap-3 shrink-0">
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

    @if ($isEmpty)
        <div class="p-8 text-center text-muted text-sm">{!! $empty !!}</div>
    @else
        <div class="overflow-x-auto">
            {{ $slot }}
        </div>
    @endif
</section>
