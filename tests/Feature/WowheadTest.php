<?php

use App\Support\Wowhead;

it('builds a bare item url when no bonuses are passed', function () {
    expect(Wowhead::url(247553))->toBe('https://www.wowhead.com/item=247553');
});

it('appends colon-joined bonus ids as a query parameter', function () {
    expect(Wowhead::url(247553, [12676, 6935, 13335]))
        ->toBe('https://www.wowhead.com/item=247553?bonus=12676:6935:13335');
});

it('builds a minimal data-wowhead attribute with just the item id', function () {
    expect(Wowhead::dataAttr(247553))->toBe('item=247553');
});

it('chains bonus / gems / enchant onto the data-wowhead attribute when provided', function () {
    $value = Wowhead::dataAttr(
        247553,
        bonusIds: [12676, 6935],
        gemIds: [240983],
        enchantId: 7987,
    );
    expect($value)->toBe('item=247553&bonus=12676:6935&gems=240983&ench=7987');
});

it('skips empty bonus / gem arrays in the data-wowhead attribute', function () {
    $value = Wowhead::dataAttr(247553, bonusIds: [], gemIds: [], enchantId: null);
    expect($value)->toBe('item=247553');
});

it('formats a SimC slug back to title case', function () {
    expect(Wowhead::formatItemName('relentless_riders_crown'))
        ->toBe('Relentless Riders Crown');
});

it('formatItemName returns null for null / empty input', function () {
    expect(Wowhead::formatItemName(null))->toBeNull();
    expect(Wowhead::formatItemName(''))->toBeNull();
    expect(Wowhead::formatItemName('   '))->toBeNull();
});
