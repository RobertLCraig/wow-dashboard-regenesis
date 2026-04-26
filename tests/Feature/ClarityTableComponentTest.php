<?php

use Illuminate\Support\HtmlString;

it('renders the title and the slot when not empty', function () {
    $html = view('components.clarity-table', [
        'title' => 'Recently inactive',
        'isEmpty' => false,
        'slot' => new HtmlString('<table class="clarity-tabular"><tbody><tr data-row><td>Foo</td></tr></tbody></table>'),
    ])->render();

    expect($html)
        ->toContain('Recently inactive')
        ->toContain('<table class="clarity-tabular">')
        ->toContain('Foo');
});

it('renders the empty message when isEmpty=true and skips the slot', function () {
    $html = view('components.clarity-table', [
        'title' => 'Bans',
        'isEmpty' => true,
        'empty' => 'No active bans.',
        'slot' => new HtmlString('<table>SHOULD NOT APPEAR</table>'),
    ])->render();

    expect($html)
        ->toContain('No active bans.')
        ->not->toContain('SHOULD NOT APPEAR');
});

it('renders the search input when searchable=true and not empty', function () {
    $html = view('components.clarity-table', [
        'title' => 'Roster',
        'isEmpty' => false,
        'searchable' => true,
        'searchPlaceholder' => 'Search name or rank...',
        'slot' => new HtmlString('<table></table>'),
    ])->render();

    expect($html)
        ->toContain('x-data="sortableTable()"')
        ->toContain('x-model="search"')
        ->toContain('Search name or rank...');
});

it('skips the search input when searchable=true but no rows', function () {
    $html = view('components.clarity-table', [
        'title' => 'Roster',
        'isEmpty' => true,
        'searchable' => true,
        'empty' => 'No members.',
        'slot' => new HtmlString('<table></table>'),
    ])->render();

    expect($html)
        ->toContain('No members.')
        ->not->toContain('x-model="search"');
});

it('renders the meta string and count when provided', function () {
    $html = view('components.clarity-table', [
        'title' => 'Vault',
        'isEmpty' => false,
        'meta' => 'wowaudit 2 hours ago',
        'count' => '5 shown',
        'slot' => new HtmlString('<table></table>'),
    ])->render();

    expect($html)
        ->toContain('wowaudit 2 hours ago')
        ->toContain('5 shown');
});

it('omits the search input wrapper attributes when searchable=false', function () {
    $html = view('components.clarity-table', [
        'title' => 'Static',
        'isEmpty' => false,
        'searchable' => false,
        'slot' => new HtmlString('<table></table>'),
    ])->render();

    expect($html)
        ->not->toContain('x-data="sortableTable()"')
        ->not->toContain('x-model="search"');
});
