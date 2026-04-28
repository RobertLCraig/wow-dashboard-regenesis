<?php

use App\Support\AddAltMacroBuilder;

it('builds a single AddAlt line for a source-target pair', function () {
    $out = AddAltMacroBuilder::build('Sheday-Silvermoon', 'Tute-Silvermoon');
    expect($out['macro'])->toBe('/run GRM.AddAlt("Sheday-Silvermoon","Tute-Silvermoon")');
    expect($out['error'])->toBeNull();
});

it('escapes embedded double quotes and backslashes in either name', function () {
    $out = AddAltMacroBuilder::build('A"-realm', 'B\\realm');
    expect($out['macro'])->toBe('/run GRM.AddAlt("A\\"-realm","B\\\\realm")');
});

it('rejects empty names', function () {
    expect(AddAltMacroBuilder::build('', 'Tute-Silvermoon')['error'])->toBe('both names required');
    expect(AddAltMacroBuilder::build('Sheday-Silvermoon', '  ')['error'])->toBe('both names required');
});

it('rejects identical source and target names', function () {
    $out = AddAltMacroBuilder::build('Sheday-Silvermoon', 'Sheday-Silvermoon');
    expect($out['error'])->toBe('cannot link a character to itself');
});

it('produces a macro line under 255 bytes for typical diacritic names', function () {
    $out = AddAltMacroBuilder::build('Ñýxx-Draenor', 'Andrômeda-ArgentDawn');
    expect($out['error'])->toBeNull();
    expect(strlen($out['macro']) <= 255)->toBeTrue();
});
