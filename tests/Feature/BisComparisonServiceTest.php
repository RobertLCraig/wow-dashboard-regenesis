<?php

use App\Models\BisProfile;
use App\Models\Member;
use App\Models\MemberEquipmentSnapshot;
use App\Models\MemberSnapshot;
use App\Models\Snapshot;
use App\Models\User;
use App\Models\WclActorParse;
use App\Models\WclFight;
use App\Models\WclReport;
use App\Services\Bis\BisComparisonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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

function bisProfileFor(string $class, string $spec, array $gear, ?string $heroTalent = null, string $source = 'simc'): BisProfile
{
    return BisProfile::query()->create([
        'class' => $class,
        'spec' => $spec,
        'hero_talent' => $heroTalent,
        'source' => $source,
        'profile_name' => "MID1_{$class}_{$spec}".($heroTalent ? "_{$heroTalent}" : '').($source !== 'simc' ? "_{$source}" : ''),
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

    expect((new BisComparisonService)->compareForMember($m))->toBeNull();
});

it('returns null when the RIO payload has no active_spec_name', function () {
    $m = bisMember();
    bisProfileFor('death_knight', 'frost', []);
    rioSnapshotFor($m, ['gear' => ['items' => []]]);  // no active_spec_name

    expect((new BisComparisonService)->compareForMember($m))->toBeNull();
});

it('returns null when no BiS profile matches the class+spec', function () {
    $m = bisMember();
    rioSnapshotFor($m, ['active_spec_name' => 'Frost', 'gear' => ['items' => []]]);
    // No bis_profiles row created.

    expect((new BisComparisonService)->compareForMember($m))->toBeNull();
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

    $result = (new BisComparisonService)->compareForMember($m);
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

    $result = (new BisComparisonService)->compareForMember($m);
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

    $result = (new BisComparisonService)->compareForMember($m);
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

    $result = (new BisComparisonService)->compareForMember($m);
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

    $result = (new BisComparisonService)->compareForMember($m);
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

    $result = (new BisComparisonService)->compareForMember($m);
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

    $result = (new BisComparisonService)->compareForMember($m);
    expect($result['slots']['neck']['gems_status'])->toBe('matched');
});

it('translates RIO singular slot names to SimC plural ones', function () {
    $m = bisMember();
    bisProfileFor('death_knight', 'frost', [
        'shoulders' => ['slot' => 'shoulders', 'name' => 'bis', 'item_id' => 100, 'enchant_id' => null, 'gem_ids' => [], 'bonus_ids' => [], 'ilevel' => null],
        'wrists' => ['slot' => 'wrists',    'name' => 'bis', 'item_id' => 200, 'enchant_id' => null, 'gem_ids' => [], 'bonus_ids' => [], 'ilevel' => null],
        'main_hand' => ['slot' => 'main_hand', 'name' => 'bis', 'item_id' => 300, 'enchant_id' => null, 'gem_ids' => [], 'bonus_ids' => [], 'ilevel' => null],
    ]);
    rioSnapshotFor($m, [
        'active_spec_name' => 'Frost',
        'gear' => ['items' => [
            'shoulder' => ['item_id' => 100, 'name' => 'rio_shoulder', 'enchants' => [], 'gems' => []],
            'wrist' => ['item_id' => 200, 'name' => 'rio_wrist',    'enchants' => [], 'gems' => []],
            'mainhand' => ['item_id' => 300, 'name' => 'rio_mh',       'enchants' => [], 'gems' => []],
        ]],
    ]);

    $result = (new BisComparisonService)->compareForMember($m);
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

    $result = (new BisComparisonService)->compareForMember($m);
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

    $result = (new BisComparisonService)->compareForMember($m);
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

    $result = (new BisComparisonService)->compareForMember($m);
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

    $result = (new BisComparisonService)->compareForMember($m);
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

    $result = (new BisComparisonService)->compareForMember($m);
    expect($result['profile_name'])->toBe('MID1_death_knight_frost');
});

it('countIssues totals missing and wrong enchants + gems separately', function () {
    $service = new BisComparisonService;
    $comparison = [
        'slots' => [
            'head' => ['enchant_status' => 'matched',        'gems_status' => 'matched'],
            'neck' => ['enchant_status' => 'missing',        'gems_status' => 'missing'],
            'chest' => ['enchant_status' => 'different',      'gems_status' => 'count_mismatch'],
            'feet' => ['enchant_status' => 'none_required',  'gems_status' => 'none_required'],
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

// ---------------------------------------------------------------
// Multi-source resolver: Blizzard equipment > Raider.IO > WCL
// ---------------------------------------------------------------

function blizzardEquipmentFor(Member $m, array $pieces): MemberEquipmentSnapshot
{
    $snap = Snapshot::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'captured_at' => now(),
        'source' => Snapshot::SOURCE_BLIZZARD_EQUIPMENT,
        'payload_hash' => bin2hex(random_bytes(8)),
    ]);

    return MemberEquipmentSnapshot::query()->create([
        'snapshot_id' => $snap->id,
        'member_id' => $m->id,
        'equipped_ilvl' => 282,
        'pieces' => $pieces,
    ]);
}

function blizzardProfileFor(Member $m, array $rawJson): MemberSnapshot
{
    $snap = Snapshot::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'captured_at' => now(),
        'source' => Snapshot::SOURCE_BLIZZARD,
        'payload_hash' => bin2hex(random_bytes(8)),
    ]);

    return MemberSnapshot::query()->create([
        'snapshot_id' => $snap->id,
        'member_id' => $m->id,
        'raw_json' => $rawJson,
    ]);
}

function wclParseFor(Member $m, string $actorSpec, array $gear): WclActorParse
{
    $report = WclReport::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'code' => bin2hex(random_bytes(4)),
        'title' => 'Fixture',
        'start_time' => now()->subHour(),
        'end_time' => now(),
        'captured_at' => now(),
    ]);
    $fight = WclFight::query()->create([
        'wcl_report_id' => $report->id,
        'fight_id' => 1,
        'encounter_id' => 1,
        'name' => 'fixture',
        'difficulty' => 5,
        'kill' => true,
        'duration_ms' => 60000,
        'start_time' => now()->subHour(),
        'end_time' => now(),
    ]);

    return WclActorParse::query()->create([
        'wcl_fight_id' => $fight->id,
        'member_id' => $m->id,
        'actor_name' => $m->name,
        'actor_class' => ucfirst(strtolower($m->class)),
        'actor_spec' => $actorSpec,
        'role' => 'dps',
        'item_level' => 282,
        'raw_json' => ['gear' => $gear],
    ]);
}

it('extracts gear from Blizzard equipment pieces (slot type + enchantments + sockets)', function () {
    $service = new BisComparisonService;
    $gear = $service->extractFromBlizzardEquipment([
        [
            'slot' => ['type' => 'HEAD'],
            'item' => ['id' => 12345],
            'name' => 'Test Helm',
            'enchantments' => [['enchantment_id' => 7961]],
            'sockets' => [['item' => ['id' => 213743]]],
        ],
        [
            'slot' => ['type' => 'FINGER_1'],
            'item' => ['id' => 555],
            'name' => 'Ring One',
            'enchantments' => [['enchantment_id' => 8001]],
            'sockets' => [],
        ],
        [
            'slot' => ['type' => 'MAIN_HAND'],
            'item' => ['id' => 9999],
            'name' => 'Big Sword',
            'enchantments' => [],
            'sockets' => [],
        ],
    ]);

    expect($gear['head']['item_id'])->toBe(12345);
    expect($gear['head']['enchant_ids'])->toBe([7961]);
    expect($gear['head']['gem_ids'])->toBe([213743]);
    expect($gear['finger1']['item_id'])->toBe(555);
    expect($gear['main_hand']['item_id'])->toBe(9999);
    expect($gear['main_hand']['enchant_ids'])->toBe([]);
});

it('skips Blizzard pieces whose enchantment row carries no enchantment_id', function () {
    // Blizzard sometimes returns a slot-level enchantment row with the
    // id field absent (slot is enchant-aware but currently unenchanted).
    $service = new BisComparisonService;
    $gear = $service->extractFromBlizzardEquipment([
        [
            'slot' => ['type' => 'CHEST'],
            'item' => ['id' => 100],
            'enchantments' => [['source_item' => ['id' => 1]]], // no enchantment_id
            'sockets' => [],
        ],
    ]);
    expect($gear['chest']['enchant_ids'])->toBe([]);
});

it('prefers Blizzard equipment over RIO when both exist', function () {
    $m = bisMember();
    bisProfileFor('death_knight', 'frost', [
        'head' => ['slot' => 'head', 'name' => 'bis', 'item_id' => 100, 'enchant_id' => null, 'gem_ids' => [], 'bonus_ids' => [], 'ilevel' => null],
    ]);
    // Blizzard says they're wearing item 100; RIO says 999. Blizzard wins.
    blizzardProfileFor($m, ['active_spec' => ['name' => 'Frost', 'id' => 251]]);
    blizzardEquipmentFor($m, [
        ['slot' => ['type' => 'HEAD'], 'item' => ['id' => 100], 'enchantments' => [], 'sockets' => []],
    ]);
    rioSnapshotFor($m, [
        'active_spec_name' => 'Frost',
        'gear' => ['items' => ['head' => ['item_id' => 999, 'name' => 'rio', 'enchants' => [], 'gems' => []]]],
    ]);

    $result = (new BisComparisonService)->compareForMember($m);
    expect($result['source'])->toBe('blizzard');
    expect($result['slots']['head']['actual_item_id'])->toBe(100);
    expect($result['slots']['head']['item_match'])->toBeTrue();
});

it('falls back to Raider.IO when Blizzard equipment is missing', function () {
    $m = bisMember();
    bisProfileFor('death_knight', 'frost', [
        'head' => ['slot' => 'head', 'name' => 'bis', 'item_id' => 100, 'enchant_id' => null, 'gem_ids' => [], 'bonus_ids' => [], 'ilevel' => null],
    ]);
    rioSnapshotFor($m, [
        'active_spec_name' => 'Frost',
        'gear' => ['items' => ['head' => ['item_id' => 100, 'name' => 'rio', 'enchants' => [], 'gems' => []]]],
    ]);

    $result = (new BisComparisonService)->compareForMember($m);
    expect($result['source'])->toBe('raiderio');
    expect($result['slots']['head']['item_match'])->toBeTrue();
});

it('falls back to WCL parse when Blizzard and RIO are both missing', function () {
    $m = bisMember();
    bisProfileFor('death_knight', 'frost', [
        'head' => ['slot' => 'head', 'name' => 'bis', 'item_id' => 100, 'enchant_id' => 7987, 'gem_ids' => [], 'bonus_ids' => [], 'ilevel' => null],
    ]);
    wclParseFor($m, 'DeathKnight-Frost', [
        ['slot' => 0, 'id' => 100, 'name' => 'wcl_head', 'permanentEnchant' => 7987],
    ]);

    $result = (new BisComparisonService)->compareForMember($m);
    expect($result['source'])->toBe('wcl');
    expect($result['slots']['head']['actual_item_id'])->toBe(100);
    expect($result['slots']['head']['enchant_status'])->toBe('matched');
});

it('resolves spec from Blizzard active_spec.name when RIO has no spec field', function () {
    // Blizzard equipment carries no spec; profile-summary does. RIO is
    // intentionally absent so the resolver has to fall through to Blizz.
    $m = bisMember(['class' => 'SHAMAN']);
    bisProfileFor('shaman', 'restoration', [
        'head' => ['slot' => 'head', 'name' => 'bis', 'item_id' => 100, 'enchant_id' => null, 'gem_ids' => [], 'bonus_ids' => [], 'ilevel' => null],
    ]);
    blizzardProfileFor($m, ['active_spec' => ['name' => 'Restoration', 'id' => 264]]);
    blizzardEquipmentFor($m, [
        ['slot' => ['type' => 'HEAD'], 'item' => ['id' => 100], 'enchantments' => [], 'sockets' => []],
    ]);

    $result = (new BisComparisonService)->compareForMember($m);
    expect($result)->not->toBeNull();
    expect($result['spec'])->toBe('restoration');
});

it('resolves spec from WCL actor_spec when nothing else has it', function () {
    // Blizzard equipment but no profile-summary, no RIO; spec must come
    // off the parse's Class-Spec string.
    $m = bisMember(['class' => 'SHAMAN']);
    bisProfileFor('shaman', 'restoration', [
        'head' => ['slot' => 'head', 'name' => 'bis', 'item_id' => 100, 'enchant_id' => null, 'gem_ids' => [], 'bonus_ids' => [], 'ilevel' => null],
    ]);
    blizzardEquipmentFor($m, [
        ['slot' => ['type' => 'HEAD'], 'item' => ['id' => 100], 'enchantments' => [], 'sockets' => []],
    ]);
    wclParseFor($m, 'Shaman-Restoration', []);

    $result = (new BisComparisonService)->compareForMember($m);
    expect($result['spec'])->toBe('restoration');
});

it('returns null when no source has any data', function () {
    $m = bisMember();
    bisProfileFor('death_knight', 'frost', []);
    expect((new BisComparisonService)->compareForMember($m))->toBeNull();
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

    $result = (new BisComparisonService)->compareForMember($m);
    expect(array_keys($result['slots']))->toBe(['head']);
});

// ---------------------------------------------------------------
// Per-source BiS table (card 0025)
// ---------------------------------------------------------------

function bisOfficer(): User
{
    return User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);
}

function bisHead(int $itemId): array
{
    return ['head' => ['slot' => 'head', 'name' => "item_{$itemId}", 'item_id' => $itemId, 'enchant_id' => null, 'gem_ids' => [], 'bonus_ids' => [], 'ilevel' => null]];
}

it('keeps every existing BiS row through the source migration', function () {
    $migration = require database_path('migrations/2026_09_29_120000_add_source_to_bis_profiles_table.php');
    $migration->down();

    $row = fn (string $name, ?string $hero, string $path) => [
        'class' => 'x', 'spec' => $name, 'hero_talent' => $hero, 'profile_name' => $name,
        'source_path' => $path, 'parsed_data' => '{}', 'captured_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ];
    DB::table('bis_profiles')->insert([
        $row('frost', null, 'profiles/MID1/MID1_Death_Knight_Frost.simc'),
        $row('frost_rider', 'rider', 'profiles/MID1/MID1_Death_Knight_Frost_Rider.simc'),
        $row('restoration', null, '/srv/regenesis/database/data/healer-bis-profiles.json'),
    ]);

    $migration->up();

    expect(DB::table('bis_profiles')->orderBy('id')->pluck('source', 'profile_name')->all())->toBe([
        'frost' => 'simc',
        'frost_rider' => 'simc',
        'restoration' => 'manual',
    ]);
});

it('defaults a DPS character to the SimC comparison', function () {
    $m = bisMember();
    // The player wears the manual row's head, so a picker that ignored the
    // source would take the manual row on gear overlap.
    bisProfileFor('death_knight', 'frost', bisHead(999), source: 'manual');
    bisProfileFor('death_knight', 'frost', bisHead(100));
    rioSnapshotFor($m, [
        'active_spec_name' => 'Frost',
        'gear' => ['items' => ['head' => ['item_id' => 999, 'name' => 'real', 'enchants' => [], 'gems' => []]]],
    ]);

    $this->actingAs(bisOfficer())->get('/character/Sheday-Silvermoon')
        ->assertOk()
        ->assertDontSee('MID1_death_knight_frost_manual')
        ->assertSee('SimC BiS');
});

it('defaults a healer to the first source that has gear', function () {
    $m = bisMember(['class' => 'SHAMAN']);
    // The player wears neither list's head, so gear overlap cannot decide.
    rioSnapshotFor($m, [
        'active_spec_name' => 'Restoration',
        'gear' => ['items' => ['head' => ['item_id' => 555, 'name' => 'real', 'enchants' => [], 'gems' => []]]],
    ]);

    // An earlier source (wowhead) with no gear is passed over for a later one (manual) that has it.
    $wowhead = bisProfileFor('shaman', 'restoration', [], source: 'wowhead');
    $wowhead->update(['captured_at' => '2026-09-22 12:00:00']);
    $manual = bisProfileFor('shaman', 'restoration', bisHead(100), source: 'manual');
    $manual->update(['captured_at' => '2026-04-30 12:00:00']);

    $this->actingAs(bisOfficer())->get('/character/Sheday-Silvermoon')
        ->assertOk()
        ->assertSee('MID1_shaman_restoration_manual')
        ->assertSee('Manual BiS')
        ->assertSee('captured 2026-04-30');

    // Once Wowhead has gear, it comes ahead of the manual stub.
    $wowhead->update(['parsed_data' => array_replace($wowhead->parsed_data, ['gear' => bisHead(100)])]);
    $manual->update(['parsed_data' => array_replace($manual->parsed_data, ['gear' => []])]);

    $this->actingAs(bisOfficer())->get('/character/Sheday-Silvermoon')
        ->assertOk()
        ->assertSee('MID1_shaman_restoration_wowhead')
        ->assertSee('Wowhead BiS')
        ->assertSee('captured 2026-09-22');
});

it('disables the tab of a source with no profile for the spec', function () {
    $m = bisMember();
    bisProfileFor('death_knight', 'frost', bisHead(100));
    rioSnapshotFor($m, [
        'active_spec_name' => 'Frost',
        'gear' => ['items' => ['head' => ['item_id' => 100, 'name' => 'Wornhelm', 'enchants' => [], 'gems' => []]]],
    ]);

    $this->actingAs(bisOfficer())->get('/character/Sheday-Silvermoon')
        ->assertOk()
        ->assertSee('aria-disabled="true" title="No Wowhead BiS for this spec"', false)
        ->assertDontSee('bis_source=wowhead', false);

    // Asking for the missing source by URL still shows a table, not an empty one.
    $this->actingAs(bisOfficer())->get('/character/Sheday-Silvermoon?bis_source=wowhead')
        ->assertOk()
        ->assertSee('SimC BiS')
        ->assertSee('Wornhelm');
});
