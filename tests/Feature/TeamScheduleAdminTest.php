<?php

use App\Models\TeamSchedule;
use App\Models\User;
use App\Services\Teams\TeamScheduleResolver;
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
        'raidhelper.timezone' => 'Europe/Paris',
        'raidhelper.default_time_of_day' => '19:30',
        'raidhelper.teams' => [
            'heroic' => [
                'label' => 'Heroic Raid',
                'channel_id' => 'CH-HEROIC',
                'raid_days' => [2, 4],
                'template_id' => '9',
            ],
            'mythic' => [
                'label' => 'Mythic Raid',
                'channel_id' => 'CH-MYTHIC',
                'raid_days' => [3, 7],
                'template_id' => '9',
            ],
        ],
    ]);
});

function adminOfficer(): User
{
    return User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);
}

it('resolver falls back to config defaults when no override exists', function () {
    $preset = TeamScheduleResolver::for('heroic');
    expect($preset['raid_days'])->toBe([2, 4]);
    expect($preset['raid_time'])->toBe('19:30');
    expect($preset['source'])->toBe('config');
});

it('resolver returns override when team_schedules row exists', function () {
    TeamSchedule::query()->create([
        'team_slug' => 'heroic',
        'raid_days' => [1, 5],
        'raid_time' => '20:15',
    ]);

    $preset = TeamScheduleResolver::for('heroic');
    expect($preset['raid_days'])->toBe([1, 5]);
    expect($preset['raid_time'])->toBe('20:15');
    expect($preset['source'])->toBe('override');
});

it('admin index renders one section per configured team', function () {
    $resp = $this->actingAs(adminOfficer())->get('/admin/teams/schedule');
    $resp->assertOk()
        ->assertSee('Heroic Raid')
        ->assertSee('Mythic Raid');
});

it('non-officer is 403d from the schedule pages', function () {
    $u = User::factory()->create(['tier' => null, 'last_role_check_at' => now()]);
    $this->actingAs($u)->get('/admin/teams/schedule')->assertStatus(403);
    $this->actingAs($u)->post('/admin/teams/schedule', [])->assertStatus(403);
});

it('saving the form upserts a team_schedules row per submitted team', function () {
    $resp = $this->actingAs(adminOfficer())->post('/admin/teams/schedule', [
        'teams' => [
            'heroic' => ['raid_days' => [1, 3, 5], 'raid_time' => '20:00'],
            'mythic' => ['raid_days' => [3, 7], 'raid_time' => '19:30'],
        ],
    ]);
    $resp->assertRedirect(route('admin.teams.schedule.index'));

    $heroic = TeamSchedule::query()->where('team_slug', 'heroic')->firstOrFail();
    expect($heroic->raid_days)->toBe([1, 3, 5]);
    expect($heroic->raid_time)->toBe('20:00');
});

it('saving rejects malformed time', function () {
    $resp = $this->actingAs(adminOfficer())->post('/admin/teams/schedule', [
        'teams' => [
            'heroic' => ['raid_days' => [2], 'raid_time' => '25:99'],
        ],
    ]);
    $resp->assertSessionHasErrors('teams.heroic.raid_time');
});

it('saving silently drops slugs not declared in config', function () {
    $this->actingAs(adminOfficer())->post('/admin/teams/schedule', [
        'teams' => [
            'heroic' => ['raid_days' => [2], 'raid_time' => '19:30'],
            'invented' => ['raid_days' => [1], 'raid_time' => '20:00'],
        ],
    ]);
    expect(TeamSchedule::query()->where('team_slug', 'invented')->exists())->toBeFalse();
});

it('reset deletes the override row', function () {
    TeamSchedule::query()->create([
        'team_slug' => 'heroic', 'raid_days' => [1], 'raid_time' => '20:00',
    ]);

    $this->actingAs(adminOfficer())
        ->post(route('admin.teams.schedule.reset', 'heroic'))
        ->assertRedirect(route('admin.teams.schedule.index'));

    expect(TeamSchedule::query()->where('team_slug', 'heroic')->exists())->toBeFalse();
});

it('saving deduplicates and clamps the days array', function () {
    $this->actingAs(adminOfficer())->post('/admin/teams/schedule', [
        'teams' => [
            'heroic' => ['raid_days' => [2, 2, 4, 4], 'raid_time' => '19:30'],
        ],
    ]);
    $row = TeamSchedule::query()->where('team_slug', 'heroic')->firstOrFail();
    expect($row->raid_days)->toBe([2, 4]);
});
