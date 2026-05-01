<?php

use App\Models\Member;
use App\Models\WclActorParse;
use App\Models\WclDeath;
use App\Models\WclFight;
use App\Models\WclReport;
use App\Services\Wcl\WclFightImporter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'grm.guild_key' => 'Regenesis-Silvermoon',
        'wcl.client_id' => 'cid',
        'wcl.client_secret' => 'csec',
        'wcl.token_url' => 'https://wcl.test/oauth/token',
        'wcl.graphql_url' => 'https://wcl.test/api/v2/client',
    ]);
    Cache::flush();
});

function deepReportResponse(): array
{
    // Damage / healing tables come back as JSON-encoded strings in
    // the real WCL response.
    $damage = json_encode(['data' => ['entries' => [
        ['name' => 'Sheday', 'type' => 'PALADIN', 'icon' => 'Paladin-Retribution', 'total' => 1_500_000, 'itemLevel' => 645],
        ['name' => 'Bruiser', 'type' => 'WARRIOR', 'icon' => 'Warrior-Arms',      'total' =>   500_000, 'itemLevel' => 640],
    ]]]);
    $healing = json_encode(['data' => ['entries' => [
        ['name' => 'Healer', 'type' => 'PRIEST', 'icon' => 'Priest-Holy', 'total' => 800_000, 'itemLevel' => 642],
    ]]]);

    // Rankings: per-fight, per-role, per-character percentile rolls.
    // Sheday parses well on the kill, Bruiser parses badly. Healer
    // gets a healing rank. Wipe (fight 2) has no rankings.
    $dpsRankings = json_encode(['data' => [
        ['fightID' => 1, 'roles' => ['dps' => ['characters' => [
            ['name' => 'Sheday',  'rankPercent' => 92.7, 'bracketPercent' => 88.2],
            ['name' => 'Bruiser', 'rankPercent' => 14.0, 'bracketPercent' => 22.0],
        ]]]],
    ]]);
    $hpsRankings = json_encode(['data' => [
        ['fightID' => 1, 'roles' => ['healers' => ['characters' => [
            ['name' => 'Healer', 'rankPercent' => 75.5, 'bracketPercent' => 70.0],
        ]]]],
    ]]);

    // Deaths table: Bruiser eats a tankbuster on the kill, Healer dies
    // twice on the wipe (battle-rezzed and dies again to a different
    // ability). Sheday survives both fights.
    $deaths = json_encode(['data' => ['entries' => [
        ['name' => 'Bruiser', 'type' => 'WARRIOR', 'icon' => 'Warrior-Arms', 'events' => [
            ['timestamp' => 250_000, 'amount' => 1_500_000, 'overkill' => 50_000,
             'ability' => ['guid' => 999111, 'name' => 'Devastating Blow', 'icon' => 'ability_warrior_devastate']],
        ]],
        ['name' => 'Healer', 'type' => 'PRIEST', 'icon' => 'Priest-Holy', 'events' => [
            ['timestamp' => 700_000, 'amount' =>   850_000, 'overkill' => 10_000,
             'ability' => ['guid' => 999222, 'name' => 'Ground Wave', 'icon' => 'spell_nature_earthquake']],
            ['timestamp' => 850_000, 'amount' =>   600_000, 'overkill' =>      0,
             'ability' => ['guid' => 999333, 'name' => 'Spike',       'icon' => 'spell_shadow_shadowbolt']],
        ]],
    ]]]);

    return ['data' => ['reportData' => ['report' => [
        'code' => 'rrrrrr',
        'title' => 'Tuesday Heroic',
        'fights' => [
            // Real boss kill: encounterID > 0, kill = true.
            ['id' => 1, 'encounterID' => 2900, 'name' => 'Plexus Sentinel', 'difficulty' => 4, 'kill' => true,  'fightPercentage' => 0,    'startTime' =>      0, 'endTime' =>  300_000],
            // Wipe at 30%.
            ['id' => 2, 'encounterID' => 2900, 'name' => 'Plexus Sentinel', 'difficulty' => 4, 'kill' => false, 'fightPercentage' => 3000, 'startTime' => 600_000, 'endTime' =>  900_000],
            // Trash pull (encounterID = 0): should be filtered out.
            ['id' => 3, 'encounterID' => 0,    'name' => 'trash',           'difficulty' => 4, 'kill' => false, 'fightPercentage' => null, 'startTime' => 950_000, 'endTime' => 1_000_000],
        ],
        'damage' => $damage,
        'healing' => $healing,
        'dpsRankings' => $dpsRankings,
        'hpsRankings' => $hpsRankings,
        'deaths' => $deaths,
    ]]]];
}

it('imports fights and parses for a report and marks fights_imported_at', function () {
    Http::fake([
        'wcl.test/oauth/token'   => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
        'wcl.test/api/v2/client' => Http::response(deepReportResponse(), 200),
    ]);
    $report = WclReport::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'code' => 'rrrrrr',
        'title' => 'Tuesday Heroic',
        'start_time' => CarbonImmutable::parse('2026-04-22 19:30'),
        'captured_at' => now(),
    ]);

    $r = WclFightImporter::fromConfig()->backfillUnimported(maxReports: 5);

    expect($r['reports_processed'])->toBe(1);
    expect($r['fights_inserted'])->toBe(2);   // trash filtered out
    // 2 dps + 1 healer per fight = 6 parses across 2 fights.
    expect($r['parses_inserted'])->toBe(6);
    expect($r['errored'])->toBe(0);

    $report->refresh();
    expect($report->fights_imported_at)->not->toBeNull();

    $kill = WclFight::query()->where('wcl_report_id', $report->id)->where('fight_id', 1)->first();
    expect($kill->kill)->toBeTrue();
    expect($kill->encounter_id)->toBe(2900);
    expect($kill->difficulty)->toBe(WclFight::DIFFICULTY_HEROIC);
    expect((float) $kill->best_percentage)->toBe(0.0);

    $wipe = WclFight::query()->where('wcl_report_id', $report->id)->where('fight_id', 2)->first();
    expect($wipe->kill)->toBeFalse();
    expect((float) $wipe->best_percentage)->toBe(30.0);  // 3000 / 100
});

it('matches the parse to a local member when names align (case-insensitive)', function () {
    Http::fake([
        'wcl.test/oauth/token'   => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
        'wcl.test/api/v2/client' => Http::response(deepReportResponse(), 200),
    ]);
    $local = Member::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'name' => 'Sheday-Silvermoon',
        'class' => 'PALADIN', 'level' => 80, 'rank_index' => 5,
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    $report = WclReport::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'code' => 'rrrrrr', 'title' => 't',
        'start_time' => CarbonImmutable::parse('2026-04-22 19:30'),
        'captured_at' => now(),
    ]);

    WclFightImporter::fromConfig()->backfillUnimported();

    $shedayParse = WclActorParse::query()->where('actor_name', 'Sheday')->first();
    expect($shedayParse->member_id)->toBe($local->id);

    $unknown = WclActorParse::query()->where('actor_name', 'Bruiser')->first();
    expect($unknown->member_id)->toBeNull();
});

it('fills parse_percentile + bracket_percentile from the rankings blobs on the kill', function () {
    Http::fake([
        'wcl.test/oauth/token'   => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
        'wcl.test/api/v2/client' => Http::response(deepReportResponse(), 200),
    ]);
    $report = WclReport::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'code' => 'rrrrrr', 'title' => 't',
        'start_time' => CarbonImmutable::parse('2026-04-22 19:30'),
        'captured_at' => now(),
    ]);

    $r = WclFightImporter::fromConfig()->backfillUnimported();
    expect($r['parses_ranked'])->toBe(3);  // sheday + bruiser dps + healer hps on fight 1

    $kill = WclFight::query()->where('wcl_report_id', $report->id)->where('fight_id', 1)->first();
    $sheday = WclActorParse::query()->where('wcl_fight_id', $kill->id)->where('actor_name', 'Sheday')->first();
    expect($sheday->parse_percentile)->toBe(93);   // 92.7 rounds to 93
    expect($sheday->bracket_percentile)->toBe(88);

    $bruiser = WclActorParse::query()->where('wcl_fight_id', $kill->id)->where('actor_name', 'Bruiser')->first();
    expect($bruiser->parse_percentile)->toBe(14);

    $healer = WclActorParse::query()->where('wcl_fight_id', $kill->id)->where('actor_name', 'Healer')->first();
    expect($healer->parse_percentile)->toBe(76);   // 75.5 rounds to 76
});

it('leaves percentile null on fights with no rankings entry (e.g. wipes)', function () {
    Http::fake([
        'wcl.test/oauth/token'   => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
        'wcl.test/api/v2/client' => Http::response(deepReportResponse(), 200),
    ]);
    $report = WclReport::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'code' => 'rrrrrr', 'title' => 't',
        'start_time' => CarbonImmutable::parse('2026-04-22 19:30'),
        'captured_at' => now(),
    ]);

    WclFightImporter::fromConfig()->backfillUnimported();

    $wipe = WclFight::query()->where('wcl_report_id', $report->id)->where('fight_id', 2)->first();
    $parses = WclActorParse::query()->where('wcl_fight_id', $wipe->id)->get();
    foreach ($parses as $p) {
        expect($p->parse_percentile)->toBeNull();
        expect($p->bracket_percentile)->toBeNull();
    }
});

it('skips reports that already have fights_imported_at set unless forced', function () {
    Http::fake([
        'wcl.test/oauth/token'   => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
        'wcl.test/api/v2/client' => Http::response(deepReportResponse(), 200),
    ]);
    WclReport::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'code' => 'rrrrrr', 'title' => 't',
        'start_time' => CarbonImmutable::parse('2026-04-22 19:30'),
        'captured_at' => now(),
        'fights_imported_at' => now()->subHour(),
    ]);

    $r = WclFightImporter::fromConfig()->backfillUnimported();
    expect($r['reports_processed'])->toBe(0);
    Http::assertNotSent(fn ($req) => str_contains($req->url(), 'wcl.test/api/v2/client'));

    // Forcing reimports.
    $r = WclFightImporter::fromConfig()->backfillUnimported(maxReports: 5, force: true);
    expect($r['reports_processed'])->toBe(1);
});

it('imports deaths with killer ability, time and overkill from the deaths table', function () {
    Http::fake([
        'wcl.test/oauth/token'   => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
        'wcl.test/api/v2/client' => Http::response(deepReportResponse(), 200),
    ]);
    $report = WclReport::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'code' => 'rrrrrr', 'title' => 't',
        'start_time' => CarbonImmutable::parse('2026-04-22 19:30'),
        'captured_at' => now(),
    ]);

    $r = WclFightImporter::fromConfig()->backfillUnimported(maxReports: 5);

    // Fixture exposes 3 death events per per-fight response (Bruiser
    // once + Healer twice). Same response replays for both kept
    // fights, so 6 rows total across the two wcl_fight rows.
    expect($r['deaths_inserted'])->toBe(6);

    $kill = WclFight::query()->where('wcl_report_id', $report->id)->where('fight_id', 1)->first();

    $bruiserDeath = WclDeath::query()
        ->where('wcl_fight_id', $kill->id)
        ->where('actor_name', 'Bruiser')
        ->first();
    expect($bruiserDeath)->not->toBeNull();
    expect($bruiserDeath->killing_ability_name)->toBe('Devastating Blow');
    expect($bruiserDeath->killing_ability_id)->toBe(999111);
    expect($bruiserDeath->killing_ability_icon)->toBe('ability_warrior_devastate');
    expect($bruiserDeath->death_time_ms)->toBe(250000);
    expect($bruiserDeath->overkill_amount)->toBe(50000);

    // Two distinct deaths for Healer on the same fight (battle rez
    // case): different death_time_ms keeps them as separate rows.
    $healerDeaths = WclDeath::query()
        ->where('wcl_fight_id', $kill->id)
        ->where('actor_name', 'Healer')
        ->orderBy('death_time_ms')
        ->get();
    expect($healerDeaths)->toHaveCount(2);
    expect($healerDeaths->pluck('killing_ability_name')->all())->toBe(['Ground Wave', 'Spike']);
});

it('matches the death to a local member when the actor name lines up', function () {
    Http::fake([
        'wcl.test/oauth/token'   => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
        'wcl.test/api/v2/client' => Http::response(deepReportResponse(), 200),
    ]);
    $local = Member::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'name' => 'Bruiser-Silvermoon',
        'class' => 'WARRIOR', 'level' => 80, 'rank_index' => 5,
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    WclReport::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'code' => 'rrrrrr', 'title' => 't',
        'start_time' => CarbonImmutable::parse('2026-04-22 19:30'),
        'captured_at' => now(),
    ]);

    WclFightImporter::fromConfig()->backfillUnimported();

    $bruiserDeath = WclDeath::query()->where('actor_name', 'Bruiser')->first();
    expect($bruiserDeath->member_id)->toBe($local->id);

    $healerDeath = WclDeath::query()->where('actor_name', 'Healer')->first();
    expect($healerDeath->member_id)->toBeNull();
});

it('replaces deaths on re-import so a corrected report does not duplicate rows', function () {
    Http::fake([
        'wcl.test/oauth/token'   => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
        'wcl.test/api/v2/client' => Http::response(deepReportResponse(), 200),
    ]);
    WclReport::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'code' => 'rrrrrr', 'title' => 't',
        'start_time' => CarbonImmutable::parse('2026-04-22 19:30'),
        'captured_at' => now(),
    ]);

    WclFightImporter::fromConfig()->backfillUnimported();
    $beforeCount = WclDeath::query()->count();
    expect($beforeCount)->toBe(6);

    WclFightImporter::fromConfig()->backfillUnimported(maxReports: 5, force: true);
    $afterCount = WclDeath::query()->count();
    expect($afterCount)->toBe(6);
});

it('counts an errored report without aborting the rest of the batch', function () {
    Http::fake([
        'wcl.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
        'wcl.test/api/v2/client' => Http::sequence()
            ->push(['errors' => [['message' => 'unauthorized']]], 200)
            ->push(deepReportResponse(), 200),
    ]);

    WclReport::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'code' => 'aaaaaa', 'title' => 'first', 'start_time' => CarbonImmutable::parse('2026-04-22 19:30'), 'captured_at' => now(),
    ]);
    WclReport::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'code' => 'rrrrrr', 'title' => 'second', 'start_time' => CarbonImmutable::parse('2026-04-23 19:30'), 'captured_at' => now(),
    ]);

    $r = WclFightImporter::fromConfig()->backfillUnimported(maxReports: 5);

    expect($r['reports_processed'])->toBe(1);
    expect($r['errored'])->toBe(1);
});
