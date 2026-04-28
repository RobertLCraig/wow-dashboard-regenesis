<?php

use App\Models\Member;
use App\Models\MemberAction;
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

function addAltMember(string $name, array $overrides = []): Member
{
    return Member::query()->create(array_replace([
        'guild_key' => 'Regenesis-Silvermoon',
        'name' => $name,
        'class' => 'PRIEST',
        'level' => 80,
        'rank_index' => 5,
        'status' => Member::STATUS_ACTIVE,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ], $overrides));
}

function addAltOfficer(): User
{
    return User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);
}

it('preview returns the macro and both characters when target name resolves', function () {
    $a = addAltMember('Sheday-Silvermoon', ['class' => 'PALADIN']);
    $b = addAltMember('Tute-Silvermoon', ['class' => 'ROGUE']);

    $resp = $this->actingAs(addAltOfficer())
        ->postJson('/roster/add-alt', [
            'source_member_id' => $a->id,
            'target_name' => 'Tute-Silvermoon',
        ]);

    $resp->assertOk()->assertJson([
        'source' => ['id' => $a->id, 'name' => 'Sheday-Silvermoon', 'class' => 'PALADIN'],
        'target' => ['id' => $b->id, 'name' => 'Tute-Silvermoon', 'class' => 'ROGUE'],
        'macro' => '/run GRM.AddAlt("Sheday-Silvermoon","Tute-Silvermoon")',
    ]);
});

it('preview 422s when the target name does not match an active member', function () {
    $a = addAltMember('Sheday-Silvermoon');

    $this->actingAs(addAltOfficer())
        ->postJson('/roster/add-alt', [
            'source_member_id' => $a->id,
            'target_name' => 'Nobody-Nowhere',
        ])
        ->assertStatus(422)
        ->assertJsonFragment(['error' => 'No active member named "Nobody-Nowhere" in this guild']);
});

it('preview 422s when source and target are the same character', function () {
    $a = addAltMember('Sheday-Silvermoon');

    $this->actingAs(addAltOfficer())
        ->postJson('/roster/add-alt', [
            'source_member_id' => $a->id,
            'target_name' => 'Sheday-Silvermoon',
        ])
        ->assertStatus(422)
        ->assertJsonFragment(['error' => 'cannot link a character to itself']);
});

it('preview 422s when source has left the guild', function () {
    $a = addAltMember('Departed-Silvermoon', ['status' => Member::STATUS_LEFT]);
    addAltMember('Tute-Silvermoon');

    $this->actingAs(addAltOfficer())
        ->postJson('/roster/add-alt', [
            'source_member_id' => $a->id,
            'target_name' => 'Tute-Silvermoon',
        ])
        ->assertStatus(422);
});

it('confirm logs MemberAction rows on both members with the cross-reference notes', function () {
    $a = addAltMember('Sheday-Silvermoon');
    $b = addAltMember('Tute-Silvermoon');
    $user = addAltOfficer();

    $resp = $this->actingAs($user)
        ->postJson('/roster/add-alt/confirm', [
            'source_member_id' => $a->id,
            'target_member_id' => $b->id,
            'notes' => 'Same player, different roles',
        ]);

    $resp->assertOk()->assertJson(['logged' => 2]);

    $aAction = MemberAction::query()->where('member_id', $a->id)->first();
    $bAction = MemberAction::query()->where('member_id', $b->id)->first();
    expect($aAction)->not->toBeNull();
    expect($aAction->action_type)->toBe(MemberAction::TYPE_ADD_ALT_MACRO);
    expect($aAction->notes)->toContain('Tute-Silvermoon');
    expect($aAction->notes)->toContain('Same player, different roles');
    expect($bAction->notes)->toContain('Sheday-Silvermoon');
});

it('non-officer is 403d from preview and confirm', function () {
    $u = User::factory()->create(['tier' => null, 'last_role_check_at' => now()]);
    $a = addAltMember('Sheday-Silvermoon');
    $b = addAltMember('Tute-Silvermoon');

    $this->actingAs($u)->postJson('/roster/add-alt', ['source_member_id' => $a->id, 'target_name' => 'Tute-Silvermoon'])->assertStatus(403);
    $this->actingAs($u)->postJson('/roster/add-alt/confirm', ['source_member_id' => $a->id, 'target_member_id' => $b->id])->assertStatus(403);
});

it('roster page renders the +Alt button + datalist with all active member names', function () {
    addAltMember('Sheday-Silvermoon');
    addAltMember('Tute-Silvermoon');
    addAltMember('Departed-Silvermoon', ['status' => Member::STATUS_LEFT]); // not in datalist

    $resp = $this->actingAs(addAltOfficer())->get('/roster');
    $resp->assertOk();

    $body = $resp->getContent();
    expect($body)->toContain('open-add-alt');
    expect($body)->toContain('id="roster-member-names"');
    expect($body)->toContain('value="Sheday-Silvermoon"');
    expect($body)->toContain('value="Tute-Silvermoon"');
    expect($body)->not->toContain('value="Departed-Silvermoon"');
});
