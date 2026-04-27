<?php

it('renders an <img> for a real WoW class regardless of casing or separator', function () {
    foreach (['MAGE', 'mage', 'Paladin', 'DEMONHUNTER', 'DemonHunter', 'Death Knight', 'death_knight', 'DK', 'DH'] as $input) {
        $html = view('components.class-icon', ['class' => $input, 'size' => 14])->render();
        expect($html)
            ->toContain('<picture')
            ->and($html)->toContain('img/icons/class/');
    }
});

it('renders nothing for raid-helper signup values that are not classes', function () {
    // Raid-Helper hands back roles + signup statuses on the same column
    // we use for the WoW class icon. None of these have a corresponding
    // class-icon image; the component should render empty rather than
    // 404'ing the asset and leaking alt text into the layout.
    foreach (['Healer', 'Ranged', 'Melee', 'Tank', 'Bench', 'Late', 'Tentative', 'Maybe', 'Absence', 'Accepted', 'Declined'] as $input) {
        $html = view('components.class-icon', ['class' => $input, 'size' => 14])->render();
        expect(trim($html))->toBe('');
    }
});

it('renders nothing for null / empty / whitespace input', function () {
    foreach ([null, '', '   '] as $input) {
        $html = view('components.class-icon', ['class' => $input, 'size' => 14])->render();
        expect(trim($html))->toBe('');
    }
});
