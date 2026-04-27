<?php

use App\Models\AltGroup;
use App\Models\EventSignup;
use App\Models\Member;
use App\Models\RaidEvent;
use App\Models\WclActorParse;
use App\Models\WclFight;
use App\Models\WclReport;
use App\Services\Attendance\AttendanceReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['grm.guild_key' => 'Regenesis-Silvermoon']);
});

function arMember(string $name, array $overrides = []): Member
{
    return Member::query()->create(array_replace([
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
}

function arEvent(int $hoursAgo = 24, string $title = 'Test raid'): RaidEvent
{
    return RaidEvent::query()->create([
        'raidhelper_event_id' => 'rh-' . uniqid('', true),
        'server_id' => 'srv',
        'channel_id' => 'CH-H',
        'title' => $title,
        'starts_at' => now()->subHours($hoursAgo),
        'ics_uid' => 'uid-' . uniqid('', true),
    ]);
}

function arSignup(RaidEvent $event, string $name, string $status = 'primary', ?string $class = null, bool $fake = false): EventSignup
{
    return EventSignup::query()->create([
        'raid_event_id' => $event->id,
        'raidhelper_signup_id' => (string) random_int(1, 999999),
        'name' => $name,
        'status' => $status,
        'class_name' => $class,
        'is_fake' => $fake,
    ]);
}

/**
 * Build a WCL report + fight + a parse per supplied actor name. The
 * fight's start_time is anchored to $hoursAgo so the matcher can pair
 * it with the corresponding RaidEvent.
 */
function arWclWithActors(int $hoursAgo, array $actorNames): WclReport
{
    $report = WclReport::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'code' => substr(md5(uniqid('', true)), 0, 8),
        'title' => 'WCL test',
        'start_time' => now()->subHours($hoursAgo),
        'captured_at' => now(),
    ]);
    $fight = WclFight::query()->create([
        'wcl_report_id' => $report->id,
        'fight_id' => 1,
        'encounter_id' => 100,
        'name' => 'Plexus Sentinel',
        'difficulty' => WclFight::DIFFICULTY_HEROIC,
        'kill' => true,
        'start_time' => now()->subHours($hoursAgo),
    ]);
    foreach ($actorNames as $n) {
        WclActorParse::query()->create([
            'wcl_fight_id' => $fight->id,
            'actor_name' => $n,
            'actor_class' => 'Priest',
            'actor_spec' => 'Discipline',
            'role' => WclActorParse::ROLE_HEALER,
        ]);
    }
    return $report;
}

it('counts a direct name match as showed up', function () {
    arMember('Sheday-Silvermoon');
    $event = arEvent(24);
    arSignup($event, 'Sheday');
    arWclWithActors(24, ['Sheday']);

    $rows = (new AttendanceReconciler)->recent('Regenesis-Silvermoon');
    expect($rows)->toHaveCount(1);
    expect($rows->first()['signed_up_count'])->toBe(1);
    expect($rows->first()['showed_up_count'])->toBe(1);
    expect($rows->first()['no_shows'])->toBe([]);
});

it('matches case-insensitively and ignores realm suffix on signup', function () {
    arMember('Sheday-Silvermoon');
    $event = arEvent(24);
    arSignup($event, 'Sheday-Silvermoon');     // signup with realm
    arWclWithActors(24, ['sheday']);            // WCL with lower-case base name

    $rows = (new AttendanceReconciler)->recent('Regenesis-Silvermoon');
    expect($rows->first()['showed_up_count'])->toBe(1);
});

it('counts signups as showed up when an alt in the same alt_group raided', function () {
    $altGroup = AltGroup::query()->create(['guild_key' => 'Regenesis-Silvermoon', 'group_label' => 'g1']);
    arMember('Sheday-Silvermoon', ['alt_group_id' => $altGroup->id]);
    arMember('Tute-Silvermoon', ['alt_group_id' => $altGroup->id]);

    $event = arEvent(24);
    arSignup($event, 'Sheday');
    arWclWithActors(24, ['Tute']);  // raided as the alt

    $rows = (new AttendanceReconciler)->recent('Regenesis-Silvermoon');
    $row = $rows->first();
    expect($row['showed_up_count'])->toBe(1);
    expect($row['no_shows'])->toBe([]);
    expect($row['showed_via_alts'])->toHaveCount(1);
    expect($row['showed_via_alts'][0]['signup_name'])->toBe('Sheday');
    expect($row['showed_via_alts'][0]['alt_name'])->toBe('Tute');
});

it('flags a signup with no matching WCL actor as a no-show', function () {
    arMember('Ghosted-Silvermoon');
    $event = arEvent(24);
    arSignup($event, 'Ghosted', 'primary', 'PRIEST');
    arWclWithActors(24, ['Someoneelse']);

    $rows = (new AttendanceReconciler)->recent('Regenesis-Silvermoon');
    $row = $rows->first();
    expect($row['signed_up_count'])->toBe(1);
    expect($row['showed_up_count'])->toBe(0);
    expect($row['no_shows'])->toHaveCount(1);
    expect($row['no_shows'][0]['name'])->toBe('Ghosted');
    expect($row['no_shows'][0]['class'])->toBe('PRIEST');
});

it('excludes non-attending statuses from the signup pool', function () {
    arMember('Real-Silvermoon');
    $event = arEvent(24);
    arSignup($event, 'Real', 'primary');
    arSignup($event, 'Bencher', 'bench');
    arSignup($event, 'Declined', 'declined');
    arSignup($event, 'Absent', 'absent');
    arSignup($event, 'Tentative', 'tentative');
    arWclWithActors(24, ['Real']);

    $rows = (new AttendanceReconciler)->recent('Regenesis-Silvermoon');
    $row = $rows->first();
    // Only Real counts as signed up; everyone else is excluded.
    expect($row['signed_up_count'])->toBe(1);
    expect($row['showed_up_count'])->toBe(1);
    expect($row['no_shows'])->toBe([]);
});

it('excludes fake signups from the signup pool', function () {
    $event = arEvent(24);
    arSignup($event, 'Real', 'primary');
    arSignup($event, 'Fakebot', 'primary', null, true);
    arWclWithActors(24, ['Real']);

    $rows = (new AttendanceReconciler)->recent('Regenesis-Silvermoon');
    expect($rows->first()['signed_up_count'])->toBe(1);
});

it('returns wcl_report = null and showed = 0 when no WCL report sits within the match window', function () {
    arMember('Sheday-Silvermoon');
    $event = arEvent(24);
    arSignup($event, 'Sheday');
    // WCL report 24 hours away from the event - well outside the +/-6h window.
    arWclWithActors(48, ['Sheday']);

    $rows = (new AttendanceReconciler)->recent('Regenesis-Silvermoon');
    $row = $rows->first();
    expect($row['wcl_report'])->toBeNull();
    expect($row['signed_up_count'])->toBe(1);
    expect($row['showed_up_count'])->toBe(0);
    // No WCL data => no no-shows (we don't blame the player for our missing data).
    expect($row['no_shows'])->toBe([]);
});

it('skips events that have not started yet', function () {
    $past = arEvent(24);
    $future = RaidEvent::query()->create([
        'raidhelper_event_id' => 'rh-future',
        'server_id' => 'srv',
        'channel_id' => 'CH-H',
        'title' => 'Future raid',
        'starts_at' => now()->addHours(6),
        'ics_uid' => 'uid-future',
    ]);
    arSignup($past, 'Sheday');
    arSignup($future, 'Sheday');

    $rows = (new AttendanceReconciler)->recent('Regenesis-Silvermoon');
    expect($rows)->toHaveCount(1);
    expect($rows->first()['event']->id)->toBe($past->id);
});

it('returns an empty collection when there are no recent past events', function () {
    $rows = (new AttendanceReconciler)->recent('Regenesis-Silvermoon');
    expect($rows->isEmpty())->toBeTrue();
});

it('limits the result to the most recent N events ordered by starts_at desc', function () {
    foreach ([6, 12, 18, 24, 36, 48, 72, 96] as $hoursAgo) {
        $e = arEvent($hoursAgo, "Raid -{$hoursAgo}h");
        arSignup($e, 'X');
    }
    $rows = (new AttendanceReconciler)->recent('Regenesis-Silvermoon', days: 30, limit: 3);
    expect($rows)->toHaveCount(3);
    // Most-recent first.
    $titles = $rows->pluck('event.title')->all();
    expect($titles[0])->toBe('Raid -6h');
    expect($titles[1])->toBe('Raid -12h');
    expect($titles[2])->toBe('Raid -18h');
});
