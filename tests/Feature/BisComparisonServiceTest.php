<?php

use App\Models\BisProfile;
use App\Models\Member;
use App\Models\MemberSnapshot;
use App\Models\Snapshot;
use App\Services\Bis\BisComparisonService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['grm.guild_key' => 'Regenesis-Silvermoon']);
});

function bisMember(array $overrides = []): Member
{
    return Member::query()->create(array_replace([
        'guild_key' => 'Regenesis-Silvermoon',
        'name' => 'Sheday-Silvermoon',
        'class' => 'DEATHKNIGHT',
        'level' => 90,
        'rank_index' => 5,
        'status' => Member::STATUS_ACTIVE,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'last_online_at' => now(),
    ], $overrides));
}

function rioSnapshotFor(Member $m, array $rawJson): MemberSnapshot
{
    $snap = Snapshot::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'captured_at' => now(),
        'source' => Snapshot::SOURCE_RAIDERIO,
        'payload_hash' => bin2hex(random_bytes(8)),
    ]);
    return MemberSnapshot::query()->create([
        'snapshot_id' => $snap->id,
        'member_id' => $m->id,
        'raw_json' => $rawJson,
        'ilvl' => 282,
    ]);
}

function bisProfileFor(string $class, string $spec, array $gear, ?string $heroTalent = null): BisProfile
{
    return BisProfile::query()->create([
        'class' => $class,
        'spec' => $spec,
        'hero_talent' => $heroTalent,
        'profile_name' => "MID1_{$class}_{$spec}" . ($heroTalent ? "_{$heroTalent}" : ''),
        'source_path' => '/fixture/path.simc',
        'parsed_data' => [
            'class' => $class,
            'spec' => $spec,
            'hero_talent' => $heroTalent,
            'gear' => $gear,
            'consumables' => ['flask' => 'flask_of_test', 'food' => 'food_of_test'],
            'gear_ilvl' => 288.5,
        ],
        'captured_at' => now(),
    ]);
}

it('returns null when the member has no Raider.IO snapshot', function () {
    $m = bisMember();
    bisProfileFor('death_knight', 'frost', []);

    expect((new BisComparisonService())->compareForMember($m))->toBeNull();
});

it('returns null when the RIO payload has no active_spec_name', function () {
    $m = bisMember();
    bisProfileFor('death_knight', 'frost', []);
    rioSnapshotFor($m, ['gear' => ['items' => []]]);  // no active_spec_name

    expect((new BisComparisonService())->compareForMember($m))->toBeNull();
});

it('returns null when no BiS profile matches the class+spec', function () {
    $m = bisMember();
    rioSnapshotFor($m, ['active_spec_name' => 'Frost', 'gear' => ['items' => []]]);
    // No bis_profiles row created.

    expect((new BisComparisonService())->compareForMember($m))->toBeNull();
});

it('matches enchant when actual array contains the BiS enchant id', function () {
    $m = bisMember();
    bisProfileFor('death_knight', 'frost', [
        'chest' => ['slot' => 'chest', 'name' => 'bis_chest', 'item_id' => 100, 'enchant_id' => 7987, 'gem_ids' => [], 'bonus_ids' => [], 'ilevel' => null],
    ]);
    rioSnapshotFor($m, [
        'active_spec_name' => 'Frost',
        'gear' => ['items' => [
            'chest' => ['item_id' => 100, 'name' => 'real_chest', 'enchants' => [7987], 'gems' => []],
        ]],
    ]);

    $result = (new BisComparisonService())->compareForMember($m);
    expect($result['slots']['chest']['enchant_status'])->toBe('matched');
    expect($result['slots']['chest']['item_match'])->toBeTrue();
});

it('flags a slot as missing when BiS expects an enchant but the player has none', function () {
    $m = bisMember();
    bisProfileFor('death_knight', 'frost', [
        'chest' => ['slot' => 'chest', 'name' => 'bis', 'item_id' => 100, 'enchant_id' => 7987, 'gem_ids' => [], 'bonus_ids' => [], 'ilevel' => null],
    ]);
    rioSnapshotFor($m, [
        'active_spec_name' => 'Frost',
        'gear' => ['items' => ['chest' => ['item_id' => 200, 'name' => 'real', 'enchants' => [], 'gems' => []]]],
    ]);

    $result = (new BisComparisonService())->compareForMember($m);
    expect($result['slots']['chest']['enchant_status'])->toBe('missing');
});

it('flags a slot as different when the player has a non-BiS enchant', function () {
    $m = bisMember();
    bisProfileFor('death_knight', 'frost', [
        'chest' => ['slot' => 'chest', 'name' => 'bis', 'item_id' => 100, 'enchant_id' => 7987, 'gem_ids' => [], 'bonus_ids' => [], 'ilevel' => null],
    ]);
    rioSnapshotFor($m, [
        'active_spec_name' => 'Frost',
        'gear' => ['items' => ['chest' => ['item_id' => 200, 'name' => 'real', 'enchants' => [9999], 'gems' => []]]],
    ]);

    $result = (new BisComparisonService())->compareForMember($m);
    expect($result['slots']['chest']['enchant_status'])->toBe('different');
});

it('returns none_required when neither BiS nor actual has an enchant', function () {
    $m = bisMember();
    bisProfileFor('death_knight', 'frost', [
        'head' => ['slot' => 'head', 'name' => 'bis', 'item_id' => 100, 'enchant_id' => null, 'gem_ids' => [], 'bonus_ids' => [], 'ilevel' => null],
    ]);
    rioSnapshotFor($m, [
        'active_spec_name' => 'Frost',
        'gear' => ['items' => ['head' => ['item_id' => 100, 'name' => 'real', 'enchants' => [], 'gems' => []]]],
    ]);

    $result = (new BisComparisonService())->compareForMember($m);
    expect($result['slots']['head']['enchant_status'])->toBe('none_required');
});

it('flags gems as missing when BiS expects N but the player has zero', function () {
    $m = bisMember();
    bisProfileFor('death_knight', 'frost', [
        'neck' => ['slot' => 'neck', 'name' => 'bis', 'item_id' => 100, 'enchant_id' => null, 'gem_ids' => [240908, 240908], 'bonus_ids' => [], 'ilevel' => null],
    ]);
    rioSnapshotFor($m, [
        'active_spec_name' => 'Frost',
        'gear' => ['items' => ['neck' => ['item_id' => 100, 'name' => 'real', 'enchants' => [], 'gems' => []]]],
    ]);

    $result = (new BisComparisonService())->compareForMember($m);
    expect($result['slots']['neck']['gems_status'])->toBe('missing');
});

it('flags gems as count_mismatch when one socket is filled but BiS expects two', function () {
    $m = bisMember();
    bisProfileFor('death_knight', 'frost', [
        'neck' => ['slot' => 'neck', 'name' => 'bis', 'item_id' => 100, 'enchant_id' => null, 'gem_ids' => [240908, 240908], 'bonus_ids' => [], 'ilevel' => null],
    ]);
    rioSnapshotFor($m, [
        'active_spec_name' => 'Frost',
        'gear' => ['items' => ['neck' => ['item_id' => 100, 'name' => 'real', 'enchants' => [], 'gems' => [240908]]]],
    ]);

    $result = (new BisComparisonService())->compareForMember($m);
    expect($result['slots']['neck']['gems_status'])->toBe('count_mismatch');
});

it('matches gems regardless of order', function () {
    $m = bisMember();
    bisProfileFor('death_knight', 'frost', [
        'neck' => ['slot' => 'neck', 'name' => 'bis', 'item_id' => 100, 'enchant_id' => null, 'gem_ids' => [101, 202], 'bonus_ids' => [], 'ilevel' => null],
    ]);
    rioSnapshotFor($m, [
        'active_spec_name' => 'Frost',
        'gear' => ['items' => ['neck' => ['item_id' => 100, 'name' => 'real', 'enchants' => [], 'gems' => [202, 101]]]],
    ]);

    $result = (new BisComparisonService())->compareForMember($m);
    expect($result['slots']['neck']['gems_status'])->toBe('matched');
});

it('translates RIO singular slot names to SimC plural ones', function () {
    $m = bisMember();
    bisProfileFor('death_knight', 'frost', [
        'shoulders' => ['slot' => 'shoulders', 'name' => 'bis', 'item_id' => 100, 'enchant_id' => null, 'gem_ids' => [], 'bonus_ids' => [], 'ilevel' => null],
        'wrists'    => ['slot' => 'wrists',    'name' => 'bis', 'item_id' => 200, 'enchant_id' => null, 'gem_ids' => [], 'bonus_ids' => [], 'ilevel' => null],
        'main_hand' => ['slot' => 'main_hand', 'name' => 'bis', 'item_id' => 300, 'enchant_id' => null, 'gem_ids' => [], 'bonus_ids' => [], 'ilevel' => null],
    ]);
    rioSnapshotFor($m, [
        'active_spec_name' => 'Frost',
        'gear' => ['items' => [
            'shoulder' => ['item_id' => 100, 'name' => 'rio_shoulder', 'enchants' => [], 'gems' => []],
            'wrist'    => ['item_id' => 200, 'name' => 'rio_wrist',    'enchants' => [], 'gems' => []],
            'mainhand' => ['item_id' => 300, 'name' => 'rio_mh',       'enchants' => [], 'gems' => []],
        ]],
    ]);

    $result = (new BisComparisonService())->compareForMember($m);
    expect($result['slots']['shoulders']['actual_item_id'])->toBe(100);
    expect($result['slots']['wrists']['actual_item_id'])->toBe(200);
    expect($result['slots']['main_hand']['actual_item_id'])->toBe(300);
});

it('picks the hero-talent variant when its BiS gear overlaps the player more than the default', function () {
    // Default profile and a Rider variant with different item ids.
    // Player's gear matches Rider's items, so Rider should win on score.
    $m = bisMember();
    bisProfileFor('death_knight', 'frost', [
        'head' => ['slot' => 'head', 'name' => 'default_head', 'item_id' => 100, 'enchant_id' => null, 'gem_ids' => [], 'bonus_ids' => [], 'ilevel' => null],
        'neck' => ['slot' => 'neck', 'name' => 'default_neck', 'item_id' => 200, 'enchant_id' => null, 'gem_ids' => [], 'bonus_ids' => [], 'ilevel' => null],
    ]);
    bisProfileFor('death_knight', 'frost', [
        'head' => ['slot' => 'head', 'name' => 'rider_head', 'item_id' => 900, 'enchant_id' => null, 'gem_ids' => [], 'bonus_ids' => [], 'ilevel' => null],
        'neck' => ['slot' => 'neck', 'name' => 'rider_neck', 'item_id' => 901, 'enchant_id' => null, 'gem_ids' => [], 'bonus_ids' => [], 'ilevel' => null],
    ], heroTalent: 'rider');
    rioSnapshotFor($m, [
        'active_spec_name' => 'Frost',
        'gear' => ['items' => [
            'head' => ['item_id' => 900, 'name' => 'rio_head', 'enchants' => [], 'gems' => []],
            'neck' => ['item_id' => 901, 'name' => 'rio_neck', 'enchants' => [], 'gems' => []],
        ]],
    ]);

    $result = (new BisComparisonService())->compareForMember($m);
    expect($result['profile_name'])->toBe('MID1_death_knight_frost_rider');
    expect($result['slots']['head']['bis_item_name'])->toBe('rider_head');
});

it('falls back to default profile (hero_talent IS NULL) when the player matches it best', function () {
    $m = bisMember();
    // Both default and a variant exist; we pick the default.
    bisProfileFor('death_knight', 'frost', [
        'head' => ['slot' => 'head', 'name' => 'default_head', 'item_id' => 100, 'enchant_id' => null, 'gem_ids' => [], 'bonus_ids' => [], 'ilevel' => null],
    ]);
    bisProfileFor('death_knight', 'frost', [
        'head' => ['slot' => 'head', 'name' => 'rider_head', 'item_id' => 999, 'enchant_id' => null, 'gem_ids' => [], 'bonus_ids' => [], 'ilevel' => null],
    ], heroTalent: 'rider');
    rioSnapshotFor($m, [
        'active_spec_name' => 'Frost',
        'gear' => ['items' => ['head' => ['item_id' => 100, 'name' => 'rio', 'enchants' => [], 'gems' => []]]],
    ]);

    $result = (new BisComparisonService())->compareForMember($m);
    expect($result['profile_name'])->toBe('MID1_death_knight_frost');
    expect($result['slots']['head']['bis_item_name'])->toBe('default_head');
});

it('normalises multi-word spec names to underscore form', function () {
    $m = bisMember(['class' => 'HUNTER']);
    bisProfileFor('hunter', 'beast_mastery', [
        'head' => ['slot' => 'head', 'name' => 'bis', 'item_id' => 1, 'enchant_id' => null, 'gem_ids' => [], 'bonus_ids' => [], 'ilevel' => null],
    ]);
    rioSnapshotFor($m, [
        'active_spec_name' => 'Beast Mastery',
        'gear' => ['items' => ['head' => ['item_id' => 1, 'name' => 'real', 'enchants' => [], 'gems' => []]]],
    ]);

    $result = (new BisComparisonService())->compareForMember($m);
    expect($result)->not->toBeNull();
    expect($result['spec'])->toBe('beast_mastery');
});

it('breaks ties by preferring the default profile over variants', function () {
    // Both default and variant have the same single matched item (tie).
    // Tie-break should land on the default.
    $m = bisMember();
    bisProfileFor('death_knight', 'frost', [
        'head' => ['slot' => 'head', 'name' => 'default_head', 'item_id' => 1, 'enchant_id' => null, 'gem_ids' => [], 'bonus_ids' => [], 'ilevel' => null],
    ]);
    bisProfileFor('death_knight', 'frost', [
        'head' => ['slot' => 'head', 'name' => 'rider_head', 'item_id' => 1, 'enchant_id' => null, 'gem_ids' => [], 'bonus_ids' => [], 'ilevel' => null],
    ], heroTalent: 'rider');
    rioSnapshotFor($m, [
        'active_spec_name' => 'Frost',
        'gear' => ['items' => ['head' => ['item_id' => 1, 'name' => 'rio', 'enchants' => [], 'gems' => []]]],
    ]);

    $result = (new BisComparisonService())->compareForMember($m);
    expect($result['profile_name'])->toBe('MID1_death_knight_frost');
});

it('falls back to default when the player has no actual gear data to score against', function () {
    $m = bisMember();
    bisProfileFor('death_knight', 'frost', [
        'head' => ['slot' => 'head', 'name' => 'default_head', 'item_id' => 1, 'enchant_id' => null, 'gem_ids' => [], 'bonus_ids' => [], 'ilevel' => null],
    ]);
    bisProfileFor('death_knight', 'frost', [
        'head' => ['slot' => 'head', 'name' => 'rider_head', 'item_id' => 99, 'enchant_id' => null, 'gem_ids' => [], 'bonus_ids' => [], 'ilevel' => null],
    ], heroTalent: 'rider');
    rioSnapshotFor($m, [
        'active_spec_name' => 'Frost',
        'gear' => ['items' => []],  // empty gear - no scoring signal
    ]);

    $result = (new BisComparisonService())->compareForMember($m);
    expect($result['profile_name'])->toBe('MID1_death_knight_frost');
});

it('countIssues totals missing and wrong enchants + gems separately', function () {
    $service = new BisComparisonService();
    $comparison = [
        'slots' => [
            'head'  => ['enchant_status' => 'matched',        'gems_status' => 'matched'],
            'neck'  => ['enchant_status' => 'missing',        'gems_status' => 'missing'],
            'chest' => ['enchant_status' => 'different',      'gems_status' => 'count_mismatch'],
            'feet'  => ['enchant_status' => 'none_required',  'gems_status' => 'none_required'],
            'waist' => ['enchant_status' => 'missing',        'gems_status' => 'different'],
        ],
    ];

    $issues = $service->countIssues($comparison);
    expect($issues['missing_enchants'])->toBe(2);
    expect($issues['wrong_enchants'])->toBe(1);
    expect($issues['missing_gems'])->toBe(1);
    expect($issues['wrong_gems'])->toBe(2);
    expect($issues['total'])->toBe(6);
});

it('skips slots where neither BiS nor actual data exists', function () {
    $m = bisMember();
    bisProfileFor('death_knight', 'frost', [
        'head' => ['slot' => 'head', 'name' => 'bis', 'item_id' => 100, 'enchant_id' => null, 'gem_ids' => [], 'bonus_ids' => [], 'ilevel' => null],
    ]);
    rioSnapshotFor($m, [
        'active_spec_name' => 'Frost',
        'gear' => ['items' => ['head' => ['item_id' => 100, 'name' => 'real', 'enchants' => [], 'gems' => []]]],
    ]);

    $result = (new BisComparisonService())->compareForMember($m);
    expect(array_keys($result['slots']))->toBe(['head']);
});
