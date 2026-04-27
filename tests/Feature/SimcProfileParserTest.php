<?php

use App\Services\Simc\SimcProfileParser;

function fixturePath(string $name): string
{
    return __DIR__ . '/../fixtures/simc/' . $name;
}

it('extracts class, spec, hero talent and profile name from a Frost DK Rider profile', function () {
    $contents = file_get_contents(fixturePath('MID1_Death_Knight_Frost_Rider.simc'));
    $parsed = (new SimcProfileParser())->parse($contents);

    expect($parsed['class'])->toBe('death_knight');
    expect($parsed['spec'])->toBe('frost');
    expect($parsed['hero_talent'])->toBe('rider');
    expect($parsed['profile_name'])->toBe('MID1_Death_Knight_Frost_Rider');
    expect($parsed['level'])->toBe(90);
});

it('returns null hero talent for a single-token class profile with no hero suffix', function () {
    $contents = file_get_contents(fixturePath('MID1_Druid_Balance.simc'));
    $parsed = (new SimcProfileParser())->parse($contents);

    expect($parsed['class'])->toBe('druid');
    expect($parsed['spec'])->toBe('balance');
    expect($parsed['hero_talent'])->toBeNull();
});

it('captures gear summary footer ilvl', function () {
    $contents = file_get_contents(fixturePath('MID1_Death_Knight_Frost_Rider.simc'));
    $parsed = (new SimcProfileParser())->parse($contents);

    expect($parsed['gear_ilvl'])->toBe(288.47);
});

it('parses every gear slot with its item id, bonuses, gems, enchant', function () {
    $contents = file_get_contents(fixturePath('MID1_Death_Knight_Frost_Rider.simc'));
    $parsed = (new SimcProfileParser())->parse($contents);
    $gear = $parsed['gear'];

    expect(array_keys($gear))->toContain(
        'head', 'neck', 'shoulders', 'back', 'chest', 'wrists',
        'hands', 'waist', 'legs', 'feet',
        'finger1', 'finger2', 'trinket1', 'trinket2', 'main_hand',
    );

    // Head: bonuses + single gem, no enchant.
    expect($gear['head']['name'])->toBe('relentless_riders_crown');
    expect($gear['head']['item_id'])->toBe(249970);
    expect($gear['head']['bonus_ids'])->toBe([40, 6935, 12676, 12806, 13335, 13338, 13575]);
    expect($gear['head']['gem_ids'])->toBe([240983]);
    expect($gear['head']['enchant_id'])->toBeNull();
    expect($gear['head']['ilevel'])->toBeNull();

    // Neck: dual gems (slash-separated).
    expect($gear['neck']['gem_ids'])->toBe([240908, 240908]);

    // Chest: enchant_id set.
    expect($gear['chest']['enchant_id'])->toBe(7987);

    // Feet: explicit ilevel override, no other modifiers.
    expect($gear['feet']['ilevel'])->toBe(289);
    expect($gear['feet']['enchant_id'])->toBeNull();
    expect($gear['feet']['gem_ids'])->toBe([]);
    expect($gear['feet']['bonus_ids'])->toBe([]);

    // Main hand: weapon enchant.
    expect($gear['main_hand']['name'])->toBe('bellamys_final_judgement');
    expect($gear['main_hand']['enchant_id'])->toBe(3368);
});

it('captures consumables including the per-hand temporary enchant split', function () {
    $contents = file_get_contents(fixturePath('MID1_Death_Knight_Frost_Rider.simc'));
    $parsed = (new SimcProfileParser())->parse($contents);
    $cons = $parsed['consumables'];

    expect($cons['potion'])->toBe('potion_of_recklessness_2');
    expect($cons['flask'])->toBe('flask_of_the_shattered_sun_2');
    expect($cons['food'])->toBe('silvermoon_parade');
    expect($cons['augmentation'])->toBe('void_touched');
    expect($cons['temporary_enchant_main_hand'])->toBe('thalassian_phoenix_oil_2');
    expect($cons['temporary_enchant_off_hand'])->toBe('thalassian_phoenix_oil_2');
});

it('skips lines that are not gear / consumables / class / spec / level', function () {
    // The fixture contains talents=, role=, position=, source= and APL
    // actions; none should leak into the parsed result.
    $contents = file_get_contents(fixturePath('MID1_Death_Knight_Frost_Rider.simc'));
    $parsed = (new SimcProfileParser())->parse($contents);

    expect($parsed)->not->toHaveKey('talents');
    expect($parsed)->not->toHaveKey('role');
    expect($parsed)->not->toHaveKey('actions');
    expect(array_keys($parsed))->toBe([
        'class', 'spec', 'hero_talent', 'profile_name',
        'level', 'gear_ilvl', 'gear', 'consumables',
    ]);
});

it('returns null class when the file has no <class>="..." declaration', function () {
    $parsed = (new SimcProfileParser())->parse("spec=frost\nlevel=90\n");
    expect($parsed['class'])->toBeNull();
    expect($parsed['profile_name'])->toBeNull();
});

it('drops gear lines without an id rather than persisting half-rows', function () {
    $stub = "deathknight=\"MID1_Death_Knight_Frost\"\nspec=frost\nlevel=90\nhead=mystery_helm,bonus_id=42\nneck=real_neck,id=12345\n";
    $parsed = (new SimcProfileParser())->parse($stub);

    expect($parsed['gear'])->toHaveKey('neck');
    expect($parsed['gear'])->not->toHaveKey('head');
});

it('normalises hyphenated hero talents to underscore form', function () {
    // Profile name "MID1_Demon_Hunter_Devourer_Void-Scarred" should
    // produce hero_talent='void_scarred' so the unique key behaves
    // predictably regardless of filename punctuation.
    $stub = 'demonhunter="MID1_Demon_Hunter_Devourer_Void-Scarred"' . "\nspec=devourer\nlevel=90\n";
    $parsed = (new SimcProfileParser())->parse($stub);

    expect($parsed['class'])->toBe('demon_hunter');
    expect($parsed['hero_talent'])->toBe('void_scarred');
});
