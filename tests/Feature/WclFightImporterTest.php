<?php

use App\Models\Member;
use App\Models\WclActorParse;
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
