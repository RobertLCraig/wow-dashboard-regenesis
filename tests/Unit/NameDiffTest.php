<?php

use App\Support\NameDiff;

it('returns plain pass-through when no sibling collides under ASCII fold', function () {
    $out = NameDiff::annotate('Sheday-Silvermoon', ['Otherchar-Silvermoon']);
    foreach ($out as [$char, $diff]) {
        expect($diff)->toBeFalse();
    }
    expect(implode('', array_column($out, 0)))->toBe('Sheday-Silvermoon');
});

it('marks the differing diacritic letter when two siblings share an ASCII fold', function () {
    // Ñýxx and Ñyxx both fold to "nyxx" but differ at position 1 (ý vs y).
    $out = NameDiff::annotate('Ñýxx-Draenor', ['Ñyxx-Draenor']);
    $rendered = '';
    foreach ($out as [$char, $diff]) {
        $rendered .= $diff ? "[{$char}]" : $char;
    }
    expect($rendered)->toBe('Ñ[ý]xx-Draenor');
});

it('marks every position that differs across a fold-collision cohort', function () {
    // Ñýxx vs Ñyxx vs Nýxx: diffs at positions 0 (Ñ/N) and 1 (ý/y)
    // collectively. From Ñýxx perspective both 0 and 1 differ.
    $out = NameDiff::annotate('Ñýxx-Draenor', ['Ñyxx-Draenor', 'Nýxx-Draenor']);
    $marked = [];
    foreach ($out as $i => [$char, $diff]) {
        if ($diff) $marked[$i] = $char;
    }
    expect($marked)->toBe([0 => 'Ñ', 1 => 'ý']);
});

it('ignores siblings that fold to a different name', function () {
    // Andrômeda folds to "andromeda"; Carîna folds to "carina". No
    // collision, so no annotation despite both having diacritics.
    $out = NameDiff::annotate('Andrômeda-ArgentDawn', ['Carîna-ArgentDawn']);
    foreach ($out as [, $diff]) {
        expect($diff)->toBeFalse();
    }
});

it('does not mark itself when the sibling list contains the input', function () {
    // Defensive: callers may pass the full member list including self.
    $out = NameDiff::annotate('Ñýxx-Draenor', ['Ñýxx-Draenor', 'Ñyxx-Draenor']);
    $marked = [];
    foreach ($out as $i => [$char, $diff]) {
        if ($diff) $marked[] = $i;
    }
    expect($marked)->toBe([1]);
});
