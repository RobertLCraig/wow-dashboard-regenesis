<?php

use App\Models\Member;
use App\Models\MemberRaidSnapshot;
use App\Models\Snapshot;
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

function aotcMember(string $name): Member
{
    return Member::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'name' => $name,
        'class' => 'PRIEST',
        'level' => 80,
        'rank_index' => 5,
        'rank_name' => 'Member',
        'status' => Member::STATUS_ACTIVE,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);
}

function aotcExpansionPayload(int $instanceId, string $instanceName, string $difficulty, int $completed, int $total): array
{
    return [
        [
            'expansion' => ['id' => 503, 'name' => 'TWW'],
            'instances' => [[
                'instance' => ['id' => $instanceId, 'name' => $instanceName],
                'modes' => [[
                    'difficulty' => ['type' => $difficulty],
                    'progress' => ['completed_count' => $completed, 'total_count' => $total, 'encounters' => []],
                ]],
            ]],
        ],
    ];
}

function aotcOfficer(): User
{
    return User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);
}

it('renders the AOTC gap widget with has-AOTC and missing-AOTC counts', function () {
    $cleared = aotcMember('Cleared-Silvermoon');
    $missing = aotcMember('Missing-Silvermoon');

    $snap = Snapshot::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'captured_at' => now(),
        'source' => Snapshot::SOURCE_BLIZZARD_RAIDS,
        'payload_hash' => 'h-aotc',
    ]);

    MemberRaidSnapshot::query()->create([
        'snapshot_id' => $snap->id,
        'member_id' => $cleared->id,
        'expansions' => aotcExpansionPayload(1296, 'Manaforge Omega', 'HEROIC', 8, 8),
    ]);
    MemberRaidSnapshot::query()->create([
        'snapshot_id' => $snap->id,
        'member_id' => $missing->id,
        'expansions' => aotcExpansionPayload(1296, 'Manaforge Omega', 'HEROIC', 5, 8),
    ]);

    $resp = $this->actingAs(aotcOfficer())->get('/dashboard');

    $resp->assertOk()
        ->assertSee('AOTC gap')
        ->assertSee('Manaforge Omega')
        ->assertSee('Missing-Silvermoon');
});

it('renders the empty state when no Blizzard raid snapshot exists yet', function () {
    aotcMember('Anyone-Silvermoon');

    $resp = $this->actingAs(aotcOfficer())->get('/dashboard');

    $resp->assertOk()
        ->assertSee('AOTC gap')
        ->assertSee('No Blizzard raid data yet');
});
