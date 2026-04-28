<?php

use App\Support\CustomNoteMacroBuilder;

it('builds a replace=true macro line for a typical note', function () {
    $out = CustomNoteMacroBuilder::build('Sheday-Silvermoon', 'Tank, raids Tue/Thu', true);
    expect($out['macro'])->toBe('/run GRM_API.EditCustomNote("Sheday-Silvermoon","Tank, raids Tue/Thu",true,false)');
    expect($out['error'])->toBeNull();
});

it('builds a replace=false (append) macro line', function () {
    $out = CustomNoteMacroBuilder::build('Sheday-Silvermoon', 'Trial week 2', false);
    expect($out['macro'])->toBe('/run GRM_API.EditCustomNote("Sheday-Silvermoon","Trial week 2",false,false)');
});

it('escapes embedded double quotes and backslashes in the note', function () {
    $out = CustomNoteMacroBuilder::build('Sheday-Silvermoon', 'said "hello\\world"', true);
    expect($out['macro'])->toContain('"said \\"hello\\\\world\\""');
});

it('escapes newlines as \\n so multi-line notes round-trip', function () {
    $out = CustomNoteMacroBuilder::build('Sheday-Silvermoon', "line one\nline two", true);
    expect($out['macro'])->toContain('"line one\\nline two"');
});

it('rejects an empty note', function () {
    $out = CustomNoteMacroBuilder::build('Sheday-Silvermoon', '', true);
    expect($out['macro'])->toBeNull();
    expect($out['error'])->toBe('note must not be empty');
});

it('rejects an empty name', function () {
    $out = CustomNoteMacroBuilder::build('  ', 'note', true);
    expect($out['macro'])->toBeNull();
    expect($out['error'])->toBe('name required');
});

it('rejects a note longer than the GRM 150-char limit', function () {
    $out = CustomNoteMacroBuilder::build('Sheday-Silvermoon', str_repeat('x', 151), true);
    expect($out['macro'])->toBeNull();
    expect($out['error'])->toBe('note exceeds GRM 150-char limit');
});

it('produces a macro line under 255 bytes for the worst-case 150-char note + diacritic name', function () {
    // 12-byte multi-byte name + max-length note. Lua escaping doesn't
    // multiply the budget meaningfully when there are no quotes/slashes.
    $out = CustomNoteMacroBuilder::build('Ñýxx-Draenor', str_repeat('a', 150), true);
    expect($out['error'])->toBeNull();
    expect(strlen($out['macro']) <= 255)->toBeTrue();
});
