<?php

use App\Models\WclDeath;
use App\Models\WclFight;
use App\Models\WclReport;
use App\Services\Wcl\DeathCauseAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['grm.guild_key' => 'Regenesis-Silvermoon']);
});

function deathReport(string $code, string $startsAt): WclReport
{
    return WclReport::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'code' => $code,
        'title' => 't',
        'start_time' => CarbonImmutable::parse($startsAt),
        'captured_at' => now(),
        'fights_imported_at' => now(),
    ]);
}

function deathFight(WclReport $report, int $fightId, int $encounterId, string $name, bool $kill): WclFight
{
    return WclFight::query()->create([
        'wcl_report_id' => $report->id,
        'fight_id' => $fightId,
        'encounter_id' => $encounterId,
        'name' => $name,
        'difficulty' => 4,
        'kill' => $kill,
        'best_percentage' => $kill ? 0 : 30,
    ]);
}

function deathRow(WclFight $fight, string $actor, string $abilityName, int $timeMs, ?int $abilityId = null): WclDeath
{
    return WclDeath::query()->create([
        'wcl_fight_id' => $fight->id,
        'actor_name' => $actor,
        'killing_ability_id' => $abilityId,
        'killing_ability_name' => $abilityName,
        'killing_ability_icon' => 'icon-' . mb_strtolower($abilityName),
        'death_time_ms' => $timeMs,
    ]);
}

it('aggregates top killing abilities per encounter across recent reports', function () {
    $report = deathReport('rrrrrr', '2026-04-22 19:30');
    $kill = deathFight($report, 1, 2900, 'Plexus Sentinel', kill: true);
    $wipe = deathFight($report, 2, 2900, 'Plexus Sentinel', kill: false);

    deathRow($kill, 'A', 'Devastating Blow', 100_000, 100);
    deathRow($kill, 'B', 'Devastating Blow', 200_000, 100);
    deathRow($wipe, 'A', 'Spike',            500_000, 200);
    deathRow($wipe, 'B', 'Spike',            600_000, 200);
    deathRow($wipe, 'C', 'Spike',            700_000, 200);
    deathRow($wipe, 'D', 'Ground Wave',      800_000, 300);

    $out = (new DeathCauseAggregator('Regenesis-Silvermoon'))->topByEncounter();

    expect($out)->toHaveCount(1);
    expect($out[0]['encounter_id'])->toBe(2900);
    expect($out[0]['total_deaths'])->toBe(6);

    // Spike (3) leads, Devastating Blow (2) next, Ground Wave (1) last.
    $names = array_column($out[0]['abilities'], 'ability_name');
    expect($names)->toBe(['Spike', 'Devastating Blow', 'Ground Wave']);
});

it('splits each ability into deaths-on-kills vs deaths-on-wipes', function () {
    $report = deathReport('rrrrrr', '2026-04-22 19:30');
    $kill = deathFight($report, 1, 2900, 'Plexus Sentinel', kill: true);
    $wipe = deathFight($report, 2, 2900, 'Plexus Sentinel', kill: false);

    deathRow($kill, 'A', 'Spike', 100_000);
    deathRow($wipe, 'B', 'Spike', 200_000);
    deathRow($wipe, 'C', 'Spike', 300_000);

    $out = (new DeathCauseAggregator('Regenesis-Silvermoon'))->topByEncounter();
    $spike = collect($out[0]['abilities'])->firstWhere('ability_name', 'Spike');

    expect($spike)->not->toBeNull();
    expect($spike['deaths'])->toBe(3);
    expect($spike['deaths_on_kills'])->toBe(1);
    expect($spike['deaths_on_wipes'])->toBe(2);
});

it('orders encounters by total deaths so the loudest wipe-cause leads', function () {
    $report = deathReport('rrrrrr', '2026-04-22 19:30');
    $bossA = deathFight($report, 1, 2900, 'Plexus Sentinel', kill: false);
    $bossB = deathFight($report, 2, 2901, 'Loom',           kill: true);

    deathRow($bossA, 'A', 'Spike', 100_000);
    deathRow($bossB, 'A', 'Web',   100_000);
    deathRow($bossB, 'B', 'Web',   200_000);
    deathRow($bossB, 'C', 'Web',   300_000);

    $out = (new DeathCauseAggregator('Regenesis-Silvermoon'))->topByEncounter();

    expect($out[0]['encounter_name'])->toBe('Loom');
    expect($out[0]['total_deaths'])->toBe(3);
    expect($out[1]['encounter_name'])->toBe('Plexus Sentinel');
});

it('returns an empty list when there are no imported reports yet', function () {
    $out = (new DeathCauseAggregator('Regenesis-Silvermoon'))->topByEncounter();

    expect($out)->toBe([]);
});

it('respects the report limit so older nights drop out of the rollup', function () {
    $oldReport = deathReport('aaaaaa', '2026-04-15 19:30');
    $newReport = deathReport('rrrrrr', '2026-04-22 19:30');

    $oldFight = deathFight($oldReport, 1, 2900, 'Plexus Sentinel', kill: false);
    $newFight = deathFight($newReport, 1, 2900, 'Plexus Sentinel', kill: false);

    deathRow($oldFight, 'A', 'OldSpike', 100_000);
    deathRow($newFight, 'A', 'NewSpike', 200_000);

    $out = (new DeathCauseAggregator('Regenesis-Silvermoon'))->topByEncounter(reportLimit: 1);

    $names = array_column($out[0]['abilities'], 'ability_name');
    expect($names)->toBe(['NewSpike']);
});

it('coalesces null / empty ability names under an Unknown bucket so the widget never breaks', function () {
    $report = deathReport('rrrrrr', '2026-04-22 19:30');
    $fight = deathFight($report, 1, 2900, 'Plexus Sentinel', kill: false);

    WclDeath::query()->create([
        'wcl_fight_id' => $fight->id,
        'actor_name' => 'A',
        'killing_ability_name' => null,
        'death_time_ms' => 100_000,
    ]);

    $out = (new DeathCauseAggregator('Regenesis-Silvermoon'))->topByEncounter();

    expect($out[0]['abilities'][0]['ability_name'])->toBe('Unknown');
});
