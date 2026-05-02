<?php

use App\Models\User;
use App\Models\WclActorParse;
use App\Models\WclFight;
use App\Models\WclReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'discord.guild_id' => '1247256415542841416',
        'discord.roles' => [
            'gm' => '1247279261434384415',
            'big6' => '1490762074584780951',
            'officer' => '1247278529163296789',
        ],
        'grm.guild_key' => 'Regenesis-Silvermoon',
    ]);
});

function reportsOfficer(): User
{
    return User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);
}

function makeReport(array $overrides = []): WclReport
{
    return WclReport::query()->create(array_replace([
        'guild_key' => 'Regenesis-Silvermoon',
        'code' => 'rpt' . substr(md5((string) microtime(true)), 0, 6),
        'title' => 'Tuesday Heroic',
        'start_time' => CarbonImmutable::parse('2026-04-22 19:30'),
        'end_time' => CarbonImmutable::parse('2026-04-22 22:30'),
        'zone_id' => 50,
        'zone_name' => 'Manaforge Omega',
        'owner_name' => 'Officer',
        'captured_at' => now(),
    ], $overrides));
}

it('non-officer is 403d from /reports and the detail page', function () {
    $u = User::factory()->create(['tier' => null, 'last_role_check_at' => now()]);
    $r = makeReport();

    $this->actingAs($u)->get('/reports')->assertStatus(403);
    $this->actingAs($u)->get("/reports/{$r->code}")->assertStatus(403);
});

it('index shows fight count and kill count for each report', function () {
    $r = makeReport();
    WclFight::query()->create(['wcl_report_id' => $r->id, 'fight_id' => 1, 'encounter_id' => 1, 'name' => 'Boss A', 'kill' => true]);
    WclFight::query()->create(['wcl_report_id' => $r->id, 'fight_id' => 2, 'encounter_id' => 2, 'name' => 'Boss B', 'kill' => true]);
    WclFight::query()->create(['wcl_report_id' => $r->id, 'fight_id' => 3, 'encounter_id' => 3, 'name' => 'Boss C', 'kill' => false]);

    $body = $this->actingAs(reportsOfficer())->get('/reports')->assertOk()->getContent();

    expect($body)->toContain('Tuesday Heroic');
    expect($body)->toContain('Manaforge Omega');
    // Kill count rendered inside the emerald-300 span; 2 kills out of 3 fights.
    expect($body)->toContain('class="text-emerald-300">2</span>');
});


it('detail shows the fights and per-actor parses with formatted metric_per_second values', function () {
    $r = makeReport();
    $kill = WclFight::query()->create([
        'wcl_report_id' => $r->id, 'fight_id' => 1, 'encounter_id' => 100,
        'name' => 'Plexus Sentinel', 'difficulty' => WclFight::DIFFICULTY_HEROIC,
        'kill' => true, 'best_percentage' => 0, 'duration_ms' => 250000,
    ]);
    WclActorParse::query()->create([
        'wcl_fight_id' => $kill->id, 'actor_name' => 'Sheday',
        'actor_class' => 'PALADIN', 'actor_spec' => 'Retribution', 'role' => WclActorParse::ROLE_DPS,
        'metric_per_second' => 1500000,
    ]);
    WclActorParse::query()->create([
        'wcl_fight_id' => $kill->id, 'actor_name' => 'Healy',
        'actor_class' => 'PRIEST', 'actor_spec' => 'Holy', 'role' => WclActorParse::ROLE_HEALER,
        'metric_per_second' => 800000,
    ]);

    $resp = $this->actingAs(reportsOfficer())->get("/reports/{$r->code}");
    $resp->assertOk()
        ->assertSee('Plexus Sentinel')
        ->assertSee('Sheday')
        ->assertSee('Healy')
        ->assertSee('1,500,000')  // Sheday's DPS formatted via number_format(0)
        ->assertSee('800,000');   // Healy's HPS formatted
});

it('detail shows "no per-actor data" message when a fight has no parses', function () {
    $r = makeReport();
    WclFight::query()->create([
        'wcl_report_id' => $r->id, 'fight_id' => 1, 'encounter_id' => 200,
        'name' => 'Greed Incarnate', 'difficulty' => WclFight::DIFFICULTY_NORMAL,
        'kill' => false,
    ]);

    $this->actingAs(reportsOfficer())->get("/reports/{$r->code}")
        ->assertOk()
        ->assertSee('Greed Incarnate')
        ->assertSee('No per-actor data captured for this pull.', false);
});

it('detail 404s an unknown report code', function () {
    $this->actingAs(reportsOfficer())->get('/reports/nonexistent')->assertStatus(404);
});

