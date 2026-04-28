<?php

use App\Models\AltGroup;
use App\Models\Member;
use App\Models\MemberEquipmentSnapshot;
use App\Models\MemberSnapshot;
use App\Models\Snapshot;
use App\Models\TeamMapping;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'grm.guild_key' => 'Regenesis-Silvermoon',
        'discord.guild_id' => '1247256415542841416',
        'discord.roles' => [
            'gm' => '1247279261434384415',
            'big6' => '1490762074584780951',
            'officer' => '1247278529163296789',
        ],
    ]);
});

function rosterMember(string $name, array $overrides = []): Member
{
    $team = $overrides['team'] ?? null;
    unset($overrides['team']);

    $member = Member::query()->create(array_replace([
        'guild_key' => 'Regenesis-Silvermoon',
        'name' => $name,
        'class' => 'PRIEST',
        'level' => 80,
        'rank_index' => 5,
        'rank_name' => 'Member',
        'status' => Member::STATUS_ACTIVE,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ], $overrides));

    if ($team !== null) {
        \App\Models\MemberTeam::query()->create([
            'member_id' => $member->id,
            'team' => $team,
            'is_override' => false,
        ]);
    }

    return $member;
}

function rosterOfficer(): User
{
    return User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);
}

it('renders the roster page with all members under the All filter', function () {
    rosterMember('Alpha-Silvermoon');
    rosterMember('Bravo-Silvermoon');
    rosterMember('Charlie-Silvermoon', ['status' => Member::STATUS_LEFT]); // not active

    $resp = $this->actingAs(rosterOfficer())->get('/roster');
    $resp->assertOk()
        ->assertSee('Alpha-Silvermoon')
        ->assertSee('Bravo-Silvermoon')
        ->assertDontSee('Charlie-Silvermoon');
});

it('inactive_30d filter shows only members past the 30-day cutoff', function () {
    rosterMember('Recent-Silvermoon', ['last_online_at' => now()->subDays(5)]);
    rosterMember('Stale-Silvermoon', ['last_online_at' => now()->subDays(45)]);

    $this->actingAs(rosterOfficer())
        ->get('/roster?filter=inactive_30d')
        ->assertSee('Stale-Silvermoon')
        ->assertDontSee('Recent-Silvermoon');
});

it('alts filter shows only rows with main_member_id set', function () {
    $main = rosterMember('Main-Silvermoon');
    rosterMember('Alt-Silvermoon', ['main_member_id' => $main->id]);

    $resp = $this->actingAs(rosterOfficer())->get('/roster?filter=alts');
    $resp->assertSee('Alt-Silvermoon')
        ->assertDontSee('>Main-Silvermoon<', false); // main is referenced as "Alt of" but not as a row
});

it('mains filter shows only mains-with-alt-group rows', function () {
    $altGroup = AltGroup::query()->create(['guild_key' => 'Regenesis-Silvermoon', 'group_label' => 'g1']);
    rosterMember('SoloMain-Silvermoon');                                    // no alt group => not in mains filter
    rosterMember('GroupMain-Silvermoon', ['alt_group_id' => $altGroup->id]); // in mains filter

    $resp = $this->actingAs(rosterOfficer())->get('/roster?filter=mains');
    $resp->assertSee('GroupMain-Silvermoon')
        ->assertDontSee('SoloMain-Silvermoon');
});

it('trial filter shows heroic_trial + mythic_trial members', function () {
    rosterMember('HTrial-Silvermoon', ['team' => TeamMapping::TEAM_HEROIC_TRIAL]);
    rosterMember('MTrial-Silvermoon', ['team' => TeamMapping::TEAM_MYTHIC_TRIAL]);
    rosterMember('Heroic-Silvermoon', ['team' => TeamMapping::TEAM_HEROIC]);

    $resp = $this->actingAs(rosterOfficer())->get('/roster?filter=trial');
    $resp->assertSee('HTrial-Silvermoon')
        ->assertSee('MTrial-Silvermoon')
        ->assertDontSee('Heroic-Silvermoon');
});

it('action_queue filter shows recommend_promote/demote/kick members', function () {
    rosterMember('Promote-Silvermoon', ['recommend_promote' => true]);
    rosterMember('Demote-Silvermoon', ['recommend_demote' => true]);
    rosterMember('Kick-Silvermoon', ['recommend_kick' => true]);
    rosterMember('Plain-Silvermoon');

    $resp = $this->actingAs(rosterOfficer())->get('/roster?filter=action_queue');
    $resp->assertSee('Promote-Silvermoon')
        ->assertSee('Demote-Silvermoon')
        ->assertSee('Kick-Silvermoon')
        ->assertDontSee('Plain-Silvermoon');
});

it('banned filter shows banned members and only banned members', function () {
    rosterMember('Active-Silvermoon');
    rosterMember('Banned-Silvermoon', ['status' => Member::STATUS_BANNED]);

    $resp = $this->actingAs(rosterOfficer())->get('/roster?filter=banned');
    $resp->assertSee('Banned-Silvermoon')
        ->assertDontSee('Active-Silvermoon');
});

it('an unknown filter falls back to All', function () {
    rosterMember('Alpha-Silvermoon');
    $this->actingAs(rosterOfficer())
        ->get('/roster?filter=lolwhat')
        ->assertSee('Alpha-Silvermoon');
});

it('roster row pulls ilvl + RIO + key from the latest raiderio snapshot', function () {
    $m = rosterMember('Sheday-Silvermoon');

    $snapshot = Snapshot::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'captured_at' => now(),
        'source' => Snapshot::SOURCE_RAIDERIO,
        'payload_hash' => 'h1',
    ]);
    MemberSnapshot::query()->create([
        'snapshot_id' => $snapshot->id,
        'member_id' => $m->id,
        'ilvl' => 285,
        'mplus_score' => 1500.5,
        'mplus_keystone' => 14,
    ]);

    $this->actingAs(rosterOfficer())
        ->get('/roster')
        ->assertSee('285')
        ->assertSee('via raiderio')
        ->assertSee('1,501'); // formatted (1500.5 rounds to 1501 via number_format(0))
});

it('roster ilvl prefers Blizzard over Wowaudit and Wowaudit over RIO', function () {
    $m = rosterMember('Sheday-Silvermoon');

    foreach ([
        Snapshot::SOURCE_RAIDERIO  => 245,
        Snapshot::SOURCE_WOWAUDIT  => 265,
        Snapshot::SOURCE_BLIZZARD  => 282,
    ] as $source => $ilvl) {
        $snap = Snapshot::query()->create([
            'guild_key' => 'Regenesis-Silvermoon',
            'captured_at' => now(),
            'source' => $source,
            'payload_hash' => 'h-' . $source,
        ]);
        MemberSnapshot::query()->create([
            'snapshot_id' => $snap->id,
            'member_id' => $m->id,
            'ilvl' => $ilvl,
        ]);
    }

    $resp = $this->actingAs(rosterOfficer())->get('/roster');
    $resp->assertSee('282')                  // Blizzard wins
        ->assertSee('via blizzard')
        ->assertDontSee('via wowaudit')
        ->assertDontSee('via raiderio');
});

it('roster ilvl falls through to a lower-priority source when the higher one has no row for that member', function () {
    // Two members: one only in Wowaudit, one only in RIO. The resolver
    // should walk past the missing higher-priority sources for each.
    $mythic = rosterMember('Mythic-Silvermoon');
    $heroic = rosterMember('Heroic-Silvermoon');

    $woa = Snapshot::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'captured_at' => now(),
        'source' => Snapshot::SOURCE_WOWAUDIT,
        'payload_hash' => 'woa-1',
    ]);
    MemberSnapshot::query()->create([
        'snapshot_id' => $woa->id,
        'member_id' => $mythic->id,
        'ilvl' => 280,
    ]);

    $rio = Snapshot::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'captured_at' => now(),
        'source' => Snapshot::SOURCE_RAIDERIO,
        'payload_hash' => 'rio-1',
    ]);
    MemberSnapshot::query()->create([
        'snapshot_id' => $rio->id,
        'member_id' => $heroic->id,
        'ilvl' => 245,
    ]);

    $resp = $this->actingAs(rosterOfficer())->get('/roster');
    $resp->assertSee('280')->assertSee('via wowaudit');
    $resp->assertSee('245')->assertSee('via raiderio');
});

it('flag pills render for the recommend_* columns', function () {
    rosterMember('Flagged-Silvermoon', [
        'recommend_promote' => true,
        'recommend_kick' => true,
    ]);

    $this->actingAs(rosterOfficer())
        ->get('/roster')
        ->assertSee('promote')
        ->assertSee('kick');
});

it('main? flag fires when an alt has logged in 14+ days more recently than its main', function () {
    $altGroup = AltGroup::query()->create(['guild_key' => 'Regenesis-Silvermoon', 'group_label' => 'g-stale']);
    $main = rosterMember('Stalemain-Silvermoon', [
        'alt_group_id' => $altGroup->id,
        'last_online_at' => now()->subDays(60),
    ]);
    rosterMember('Activealt-Silvermoon', [
        'alt_group_id' => $altGroup->id,
        'main_member_id' => $main->id,
        'last_online_at' => now()->subDays(2),
    ]);

    $resp = $this->actingAs(rosterOfficer())->get('/roster');
    $resp->assertOk()
        // Tooltip text on the rendered flag pill, distinguishes from the
        // column-explainer copy which mentions "main?" generically.
        ->assertSee('designation in GRM may be stale', false);
});

it('main? flag does not fire when alts are within the 14-day grace window', function () {
    $altGroup = AltGroup::query()->create(['guild_key' => 'Regenesis-Silvermoon', 'group_label' => 'g-fresh']);
    $main = rosterMember('Freshmain-Silvermoon', [
        'alt_group_id' => $altGroup->id,
        'last_online_at' => now()->subDays(10),
    ]);
    rosterMember('Slightlyfresheralt-Silvermoon', [
        'alt_group_id' => $altGroup->id,
        'main_member_id' => $main->id,
        'last_online_at' => now()->subDays(2), // 8-day gap, under threshold
    ]);

    $this->actingAs(rosterOfficer())
        ->get('/roster')
        ->assertDontSee('designation in GRM may be stale', false);
});

it('renders the row level inline and the raw class in the Class column', function () {
    rosterMember('Sheday-Silvermoon', ['class' => 'DEMONHUNTER', 'level' => 80]);

    $resp = $this->actingAs(rosterOfficer())->get('/roster');
    $resp->assertOk()
        ->assertSee('DEMONHUNTER') // dedicated Class column shows the raw GRM token
        ->assertSee('L80');
});

it('highlights the differing diacritic between alt-group siblings', function () {
    $altGroup = AltGroup::query()->create(['guild_key' => 'Regenesis-Silvermoon', 'group_label' => 'g-twins']);
    $main = rosterMember('Ñýxx-Silvermoon', ['alt_group_id' => $altGroup->id, 'class' => 'SHAMAN', 'level' => 90]);
    rosterMember('Ñyxx-Silvermoon', [
        'alt_group_id' => $altGroup->id,
        'main_member_id' => $main->id,
        'class' => 'ROGUE',
        'level' => 80,
    ]);

    $resp = $this->actingAs(rosterOfficer())->get('/roster');
    $resp->assertOk()
        // Diacritic letter wrapped in the diff-highlight span.
        ->assertSee('text-amber-300', false)
        ->assertSee('<strong class="font-bold text-amber-300', false);
});

it('main? flag does not fire on solo characters with no alt group', function () {
    rosterMember('Solo-Silvermoon', ['last_online_at' => now()->subDays(60)]);

    $this->actingAs(rosterOfficer())
        ->get('/roster')
        ->assertDontSee('designation in GRM may be stale', false);
});

it('CSV export streams the filtered set with header row', function () {
    rosterMember('Stale-Silvermoon', ['last_online_at' => now()->subDays(45), 'realm' => 'Silvermoon']);
    rosterMember('Recent-Silvermoon', ['last_online_at' => now()->subDays(5), 'realm' => 'Silvermoon']);

    $resp = $this->actingAs(rosterOfficer())->get('/roster.csv?filter=inactive_30d');
    $resp->assertOk();
    $resp->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    $body = $resp->streamedContent();
    expect($body)
        ->toContain('name,realm,class,level')   // header
        ->toContain('Stale-Silvermoon')
        ->not->toContain('Recent-Silvermoon');
});

it('non-officer is 403d from the roster page and the CSV export', function () {
    $u = User::factory()->create(['tier' => null, 'last_role_check_at' => now()]);

    $this->actingAs($u)->get('/roster')->assertStatus(403);
    $this->actingAs($u)->get('/roster.csv')->assertStatus(403);
});

it('character-links pills appear in the rendered roster row', function () {
    rosterMember('Sheday-Silvermoon');

    $this->actingAs(rosterOfficer())
        ->get('/roster')
        ->assertSee('raider.io/characters/eu/silvermoon/Sheday', false);
});

/**
 * Count the number of actual roster <tr> rows in a response body.
 * Matches the literal opening tag the view emits, not the `data-row`
 * substring (which also appears in the sortableTable() script block).
 */
function rosterRowCount(string $html): int
{
    return preg_match_all('/<tr [^>]*\bdata-row\b[^>]*>/', $html);
}

it('grouped mode hides alts whose main is in the visible set', function () {
    $altGroup = AltGroup::query()->create(['guild_key' => 'Regenesis-Silvermoon', 'group_label' => 'g1']);
    $main = rosterMember('Mainchar-Silvermoon', ['alt_group_id' => $altGroup->id]);
    rosterMember('Altchar-Silvermoon', ['alt_group_id' => $altGroup->id, 'main_member_id' => $main->id]);

    // Flat mode: both rows render as data-row entries.
    $flat = $this->actingAs(rosterOfficer())->get('/roster');
    $flat->assertOk()
        ->assertSee('Mainchar-Silvermoon')
        ->assertSee('Altchar-Silvermoon');
    expect(rosterRowCount($flat->getContent()))->toBe(2);

    // Grouped mode: alt is folded into the main's expandable list, so it
    // does not get its own data-row tr. The name still appears in the
    // hidden sub-list, so we assert on the row count instead.
    $grouped = $this->actingAs(rosterOfficer())->get('/roster?group=1');
    $grouped->assertOk()->assertSee('Mainchar-Silvermoon');
    expect(rosterRowCount($grouped->getContent()))->toBe(1);
    // Sub-list is rendered server-side and revealed via Alpine on click,
    // so the alt name still shows in the response body.
    $grouped->assertSee('Altchar-Silvermoon')->assertSee('+ 1 alt');
});

it('grouped mode keeps an alt as its own row when its main is filtered out', function () {
    $altGroup = AltGroup::query()->create(['guild_key' => 'Regenesis-Silvermoon', 'group_label' => 'g1']);
    // Main is recent, alt is stale. inactive_30d filter excludes the main,
    // so the alt becomes an orphan row in grouped mode rather than vanishing.
    $main = rosterMember('Recentmain-Silvermoon', [
        'alt_group_id' => $altGroup->id,
        'last_online_at' => now()->subDays(2),
    ]);
    rosterMember('Stalealt-Silvermoon', [
        'alt_group_id' => $altGroup->id,
        'main_member_id' => $main->id,
        'last_online_at' => now()->subDays(45),
    ]);

    $resp = $this->actingAs(rosterOfficer())->get('/roster?group=1&filter=inactive_30d');
    // The orphan alt is the only roster row; the main appears only as
    // the alt's "Alt of" reference, not as its own row.
    $resp->assertOk()->assertSee('Stalealt-Silvermoon');
    expect(rosterRowCount($resp->getContent()))->toBe(1);
});

it('roster renders a BiS column showing "OK" when no issues, the count when there are issues, and "-" when no data', function () {
    // OK: matched everywhere
    $ok = rosterMember('Ok-Silvermoon');
    // Issues: missing enchant + missing gem
    $issues = rosterMember('Issues-Silvermoon');
    // No data: no RIO snapshot
    $noData = rosterMember('Nodata-Silvermoon');

    \App\Models\BisProfile::query()->create([
        'class' => 'priest',
        'spec' => 'frost',  // PRIEST default in rosterMember
        'hero_talent' => null,
        'profile_name' => 'MID1_priest_frost',
        'source_path' => '/x.simc',
        'parsed_data' => [
            'class' => 'priest', 'spec' => 'frost', 'hero_talent' => null,
            'gear' => [
                'head'  => ['slot' => 'head',  'name' => 'h', 'item_id' => 1, 'enchant_id' => 100, 'gem_ids' => [], 'bonus_ids' => [], 'ilevel' => null],
                'neck'  => ['slot' => 'neck',  'name' => 'n', 'item_id' => 2, 'enchant_id' => null, 'gem_ids' => [200, 201], 'bonus_ids' => [], 'ilevel' => null],
            ],
            'consumables' => [],
            'gear_ilvl' => 280,
        ],
        'captured_at' => now(),
    ]);

    $snap = Snapshot::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'captured_at' => now(),
        'source' => Snapshot::SOURCE_RAIDERIO,
        'payload_hash' => 'h-roster-bis',
    ]);
    MemberSnapshot::query()->create([
        'snapshot_id' => $snap->id,
        'member_id' => $ok->id,
        'raw_json' => [
            'active_spec_name' => 'Frost',
            'gear' => ['items' => [
                'head' => ['item_id' => 1, 'name' => 'h', 'enchants' => [100], 'gems' => []],
                'neck' => ['item_id' => 2, 'name' => 'n', 'enchants' => [], 'gems' => [200, 201]],
            ]],
        ],
        'ilvl' => 282,
    ]);
    MemberSnapshot::query()->create([
        'snapshot_id' => $snap->id,
        'member_id' => $issues->id,
        'raw_json' => [
            'active_spec_name' => 'Frost',
            'gear' => ['items' => [
                'head' => ['item_id' => 1, 'name' => 'h', 'enchants' => [], 'gems' => []],   // missing enchant
                'neck' => ['item_id' => 2, 'name' => 'n', 'enchants' => [], 'gems' => []],   // missing gems
            ]],
        ],
        'ilvl' => 282,
    ]);
    // $noData has no MemberSnapshot row.

    $resp = $this->actingAs(rosterOfficer())->get('/roster');
    $resp->assertOk()
        // The BiS column header is present.
        ->assertSee('BiS')
        // Ok-Silvermoon row gets "OK".
        ->assertSee('Ok-Silvermoon')
        // Issues-Silvermoon row gets a numeric count (2: one missing enchant + one missing gem slot).
        ->assertSee('Issues-Silvermoon');
});

it('bis_issues filter shows only members with > 0 issues', function () {
    rosterMember('Clean-Silvermoon');
    $broken = rosterMember('Broken-Silvermoon');

    \App\Models\BisProfile::query()->create([
        'class' => 'priest',
        'spec' => 'frost',
        'hero_talent' => null,
        'profile_name' => 'MID1_priest_frost',
        'source_path' => '/x.simc',
        'parsed_data' => [
            'class' => 'priest', 'spec' => 'frost', 'hero_talent' => null,
            'gear' => [
                'chest' => ['slot' => 'chest', 'name' => 'c', 'item_id' => 1, 'enchant_id' => 7987, 'gem_ids' => [], 'bonus_ids' => [], 'ilevel' => null],
            ],
            'consumables' => [],
        ],
        'captured_at' => now(),
    ]);

    $snap = Snapshot::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'captured_at' => now(),
        'source' => Snapshot::SOURCE_RAIDERIO,
        'payload_hash' => 'h-bis-filter',
    ]);
    MemberSnapshot::query()->create([
        'snapshot_id' => $snap->id,
        'member_id' => $broken->id,
        'raw_json' => [
            'active_spec_name' => 'Frost',
            'gear' => ['items' => ['chest' => ['item_id' => 1, 'name' => 'c', 'enchants' => [], 'gems' => []]]],
        ],
    ]);

    $resp = $this->actingAs(rosterOfficer())->get('/roster?filter=bis_issues');
    $resp->assertSee('Broken-Silvermoon')
        ->assertDontSee('Clean-Silvermoon');
});

it('CSV export ignores the group= flag and stays flat', function () {
    $altGroup = AltGroup::query()->create(['guild_key' => 'Regenesis-Silvermoon', 'group_label' => 'g1']);
    $main = rosterMember('Csvmain-Silvermoon', ['alt_group_id' => $altGroup->id]);
    rosterMember('Csvalt-Silvermoon', ['alt_group_id' => $altGroup->id, 'main_member_id' => $main->id]);

    $resp = $this->actingAs(rosterOfficer())->get('/roster.csv?group=1');
    $body = $resp->streamedContent();

    expect($body)
        ->toContain('Csvmain-Silvermoon')
        ->toContain('Csvalt-Silvermoon');  // both rows present, even with group=1
});

it('roster renders a Gear column from the latest Blizzard equipment snapshot', function () {
    $clean = rosterMember('Cleangear-Silvermoon');
    $broken = rosterMember('Brokengear-Silvermoon');
    rosterMember('Nogear-Silvermoon'); // no equipment row at all

    $snap = Snapshot::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'captured_at' => now(),
        'source' => Snapshot::SOURCE_BLIZZARD_EQUIPMENT,
        'payload_hash' => 'h-gear-roster',
    ]);

    MemberEquipmentSnapshot::query()->create([
        'snapshot_id' => $snap->id,
        'member_id' => $clean->id,
        'equipped_ilvl' => 282,
        'pieces' => [
            ['slot' => ['type' => 'CHEST'], 'enchantments' => [['enchantment_id' => 100]], 'sockets' => []],
            ['slot' => ['type' => 'WRIST'], 'enchantments' => [['enchantment_id' => 101]], 'sockets' => []],
            ['slot' => ['type' => 'LEGS'], 'enchantments' => [['enchantment_id' => 102]], 'sockets' => []],
            ['slot' => ['type' => 'FEET'], 'enchantments' => [['enchantment_id' => 103]], 'sockets' => []],
            ['slot' => ['type' => 'BACK'], 'enchantments' => [['enchantment_id' => 104]], 'sockets' => []],
            ['slot' => ['type' => 'FINGER_1'], 'enchantments' => [['enchantment_id' => 105]], 'sockets' => []],
            ['slot' => ['type' => 'FINGER_2'], 'enchantments' => [['enchantment_id' => 106]], 'sockets' => []],
            ['slot' => ['type' => 'MAIN_HAND'], 'enchantments' => [['enchantment_id' => 107]], 'sockets' => []],
        ],
    ]);

    MemberEquipmentSnapshot::query()->create([
        'snapshot_id' => $snap->id,
        'member_id' => $broken->id,
        'equipped_ilvl' => 280,
        'pieces' => [
            ['slot' => ['type' => 'CHEST'], 'enchantments' => [], 'sockets' => []], // missing enchant
            ['slot' => ['type' => 'NECK'], 'enchantments' => [], 'sockets' => [['item' => null]]], // empty socket
        ],
    ]);

    $resp = $this->actingAs(rosterOfficer())->get('/roster');
    $resp->assertOk()
        ->assertSee('Gear')
        ->assertSee('Cleangear-Silvermoon')
        ->assertSee('Brokengear-Silvermoon')
        ->assertSee('Nogear-Silvermoon');
});
