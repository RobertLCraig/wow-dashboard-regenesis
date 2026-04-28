<?php

use App\Models\AltGroup;
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

function setMainMember(string $name, array $overrides = []): Member
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

function setMainOfficer(): User
{
    return User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);
}

it('preview returns a SetMain macro for an alt-group member', function () {
    $altGroup = AltGroup::query()->create(['guild_key' => 'Regenesis-Silvermoon', 'group_label' => 'g1']);
    $m = setMainMember('Sheday-Silvermoon', ['alt_group_id' => $altGroup->id, 'class' => 'SHAMAN']);

    $resp = $this->actingAs(setMainOfficer())
        ->postJson('/roster/set-main', ['member_ids' => [$m->id]]);

    $resp->assertOk()->assertJson([
        'macros' => ['/run GRM.SetMain("Sheday-Silvermoon")'],
        'characters' => [['id' => $m->id, 'name' => 'Sheday-Silvermoon', 'class' => 'SHAMAN']],
        'skipped' => [],
        'oversized' => [],
    ]);
});

it('preview skips members not in an alt group', function () {
    $solo = setMainMember('Solo-Silvermoon');

    $resp = $this->actingAs(setMainOfficer())
        ->postJson('/roster/set-main', ['member_ids' => [$solo->id]]);

    $resp->assertOk()
        ->assertJson(['macros' => [], 'characters' => []])
        ->assertJsonFragment(['name' => 'Solo-Silvermoon', 'reason' => 'not linked to an alt group in GRM']);
});

it('preview skips banned and former members', function () {
    $altGroup = AltGroup::query()->create(['guild_key' => 'Regenesis-Silvermoon', 'group_label' => 'g1']);
    $left = setMainMember('Departed-Silvermoon', ['alt_group_id' => $altGroup->id, 'status' => Member::STATUS_LEFT]);
    $banned = setMainMember('Outlaw-Silvermoon', ['alt_group_id' => $altGroup->id, 'status' => Member::STATUS_BANNED]);

    $resp = $this->actingAs(setMainOfficer())
        ->postJson('/roster/set-main', ['member_ids' => [$left->id, $banned->id]]);

    $resp->assertOk()
        ->assertJsonFragment(['name' => 'Departed-Silvermoon', 'reason' => 'no longer in guild'])
        ->assertJsonFragment(['name' => 'Outlaw-Silvermoon', 'reason' => 'banned']);
});

it('preview rejects an empty member_ids array', function () {
    $this->actingAs(setMainOfficer())
        ->postJson('/roster/set-main', ['member_ids' => []])
        ->assertStatus(422);
});

it('confirm logs a MemberAction with type set_main_macro', function () {
    $altGroup = AltGroup::query()->create(['guild_key' => 'Regenesis-Silvermoon', 'group_label' => 'g1']);
    $a = setMainMember('Sheday-Silvermoon', ['alt_group_id' => $altGroup->id]);
    $solo = setMainMember('Solo-Silvermoon'); // skipped by confirm too

    $user = setMainOfficer();
    $resp = $this->actingAs($user)
        ->postJson('/roster/set-main/confirm', [
            'member_ids' => [$a->id, $solo->id],
            'notes' => 'Fixed drifted main',
        ]);

    $resp->assertOk()->assertJson(['logged' => 1]);

    expect(MemberAction::query()->where('member_id', $a->id)->where('action_type', MemberAction::TYPE_SET_MAIN_MACRO)->exists())->toBeTrue();
    expect(MemberAction::query()->where('member_id', $solo->id)->exists())->toBeFalse();

    $action = MemberAction::query()->where('member_id', $a->id)->first();
    expect($action->reviewed_by_user_id)->toBe($user->id);
    expect($action->decision)->toBe(MemberAction::DECISION_ACCEPTED);
    expect($action->notes)->toBe('Fixed drifted main');
});

it('non-officer is 403d from preview and confirm', function () {
    $u = User::factory()->create(['tier' => null, 'last_role_check_at' => now()]);
    $altGroup = AltGroup::query()->create(['guild_key' => 'Regenesis-Silvermoon', 'group_label' => 'g1']);
    $m = setMainMember('Sheday-Silvermoon', ['alt_group_id' => $altGroup->id]);

    $this->actingAs($u)->postJson('/roster/set-main', ['member_ids' => [$m->id]])->assertStatus(403);
    $this->actingAs($u)->postJson('/roster/set-main/confirm', ['member_ids' => [$m->id]])->assertStatus(403);
});

it('roster row exposes the Main button only for alt-group members', function () {
    $altGroup = AltGroup::query()->create(['guild_key' => 'Regenesis-Silvermoon', 'group_label' => 'g1']);
    setMainMember('Inagroup-Silvermoon', ['alt_group_id' => $altGroup->id]);
    setMainMember('Solo-Silvermoon');

    $resp = $this->actingAs(setMainOfficer())->get('/roster');
    $resp->assertOk();

    $body = $resp->getContent();
    expect($body)->toContain('open-set-main');
});
