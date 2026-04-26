<?php

use App\Services\Grm\GrmNormalizer;

/**
 * Locks the GRM type-code -> name map. Codes are fixed by upstream
 * GRM_Log.lua; if someone re-guesses them (the original mapping was
 * empirical and shipped with kicks tagged EVENT_BIRTHDAY, etc.), this
 * test fails fast.
 */
it('maps every documented GRM log code to the right type name', function (int $code, string $expected) {
    expect(GrmNormalizer::logTypeName($code))->toBe($expected);
})->with([
    [1, 'PROMOTED'],
    [2, 'DEMOTED'],
    [3, 'LEVEL_UP'],
    [4, 'PUBLIC_NOTE'],
    [5, 'OFFICER_NOTE'],
    [6, 'RANK_RENAME'],
    [7, 'REJOINED'],
    [8, 'JOINED'],
    [9, 'REJOINED_BANNED'],
    [11, 'NAME_CHANGE'],
    [14, 'INACTIVE_RETURN'],
    [16, 'RECOMMEND_KICK'],
    [22, 'RECOMMEND_PROMOTE'],
    [23, 'RECOMMEND_DEMOTE'],
    [24, 'HARDCORE_DEATH'],
    [25, 'RECOMMEND_SPECIAL'],
]);

it('disambiguates code 10 by the playerWasKicked boolean', function () {
    // Row shape: [type, message, unitName, playerWasKicked, ...]
    $kicked = [10, 'msg', 'Ronen', true];
    $left = [10, 'msg', 'Ronen', false];

    expect(GrmNormalizer::logTypeName(10, $kicked))->toBe('KICKED');
    expect(GrmNormalizer::logTypeName(10, $left))->toBe('LEFT');
});

it('splits code 15 into birthday vs anniversary by message text', function () {
    expect(GrmNormalizer::logTypeName(15, [], "It is Foo's birthday today!"))->toBe('EVENT_BIRTHDAY');
    expect(GrmNormalizer::logTypeName(15, [], 'Foo has been in the guild for 3 years!'))->toBe('EVENT_ANNIVERSARY');
    expect(GrmNormalizer::logTypeName(15, [], 'Foo anniversary'))->toBe('EVENT_ANNIVERSARY');
    expect(GrmNormalizer::logTypeName(15, [], 'Some unrelated event'))->toBe('EVENT');
});

it('returns null for codes outside the known map', function () {
    expect(GrmNormalizer::logTypeName(0))->toBeNull();
    expect(GrmNormalizer::logTypeName(99))->toBeNull();
});
