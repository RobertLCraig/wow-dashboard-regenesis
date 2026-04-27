<?php

use App\Services\Dashboard\WidgetOrderResolver;

function testWidgets(): array
{
    return [
        ['key' => 'a', 'title' => 'A', 'partial' => 'a', 'data_key' => 'a', 'col_span' => ''],
        ['key' => 'b', 'title' => 'B', 'partial' => 'b', 'data_key' => 'b', 'col_span' => ''],
        ['key' => 'c', 'title' => 'C', 'partial' => 'c', 'data_key' => 'c', 'col_span' => ''],
    ];
}

it('returns the default order when user has no saved layout', function () {
    $resolved = WidgetOrderResolver::resolve(testWidgets(), null);
    expect(array_column($resolved, 'key'))->toBe(['a', 'b', 'c']);
});

it('returns the default order when user layout is empty', function () {
    $resolved = WidgetOrderResolver::resolve(testWidgets(), []);
    expect(array_column($resolved, 'key'))->toBe(['a', 'b', 'c']);
});

it('reorders widgets according to the user layout', function () {
    $resolved = WidgetOrderResolver::resolve(testWidgets(), ['c', 'a', 'b']);
    expect(array_column($resolved, 'key'))->toBe(['c', 'a', 'b']);
});

it('appends widgets missing from the user layout in default order', function () {
    $resolved = WidgetOrderResolver::resolve(testWidgets(), ['c']);
    expect(array_column($resolved, 'key'))->toBe(['c', 'a', 'b']);
});

it('skips unknown keys in the user layout (deleted widgets, typos)', function () {
    $resolved = WidgetOrderResolver::resolve(testWidgets(), ['c', 'unknown', 'a']);
    expect(array_column($resolved, 'key'))->toBe(['c', 'a', 'b']);
});

it('deduplicates if a key appears twice in the saved layout', function () {
    $resolved = WidgetOrderResolver::resolve(testWidgets(), ['c', 'a', 'c', 'b']);
    expect(array_column($resolved, 'key'))->toBe(['c', 'a', 'b']);
});

it('handles non-string entries gracefully', function () {
    $resolved = WidgetOrderResolver::resolve(testWidgets(), ['c', null, 42, 'a']);
    expect(array_column($resolved, 'key'))->toBe(['c', 'a', 'b']);
});
