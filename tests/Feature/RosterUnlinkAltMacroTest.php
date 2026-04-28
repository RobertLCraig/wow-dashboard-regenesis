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

function unlinkMember(string $name, array $overrides = []): Member
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

function unlinkOfficer(): User
{
    return User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);
}

it('preview returns an Unlink macro for an alt-group member', function () {
    $altGroup = AltGroup::query()->create(['guild_key' => 'Regenesis-Silvermoon', 'group_label' => 'g1']);
    $m = unlinkMember('Sheday-Silvermoon', ['alt_group_id' => $altGroup->id]);

    $resp = $this->actingAs(unlinkOfficer())
        ->postJson('/roster/unlink-alt', ['member_ids' => [$m->id]]);

    $resp->assertOk()->assertJson([
        'macros' => ['/run GRM.RemovePlayerFromAltGroup("Sheday-Silvermoon")'],
        'characters' => [['id' => $m->id, 'name' => 'Sheday-Silvermoon']],
        'skipped' => [],
        'oversized' => [],
    ]);
});

it('preview skips members not in an alt group', function () {
    $solo = unlinkMember('Solo-Silvermoon');

    $resp = $this->actingAs(unlinkOfficer())
        ->postJson('/roster/unlink-alt', ['member_ids' => [$solo->id]]);

    $resp->assertOk()
        ->assertJson(['macros' => [], 'characters' => []])
        ->assertJsonFragment(['name' => 'Solo-Silvermoon', 'reason' => 'not linked to an alt group in GRM']);
});

it('preview skips banned and former members', function () {
    $altGroup = AltGroup::query()->create(['guild_key' => 'Regenesis-Silvermoon', 'group_label' => 'g1']);
    $left = unlinkMember('Departed-Silvermoon', ['alt_group_id' => $altGroup->id, 'status' => Member::STATUS_LEFT]);
    $banned = unlinkMember('Outlaw-Silvermoon', ['alt_group_id' => $altGroup->id, 'status' => Member::STATUS_BANNED]);

    $resp = $this->actingAs(unlinkOfficer())
        ->postJson('/roster/unlink-alt', ['member_ids' => [$left->id, $banned->id]]);

    $resp->assertOk()
        ->assertJsonFragment(['name' => 'Departed-Silvermoon', 'reason' => 'no longer in guild'])
        ->assertJsonFragment(['name' => 'Outlaw-Silvermoon', 'reason' => 'banned']);
});

it('preview rejects an empty member_ids array', function () {
    $this->actingAs(unlinkOfficer())
        ->postJson('/roster/unlink-alt', ['member_ids' => []])
        ->assertStatus(422);
});

it('confirm logs a MemberAction with type unlink_alt_macro', function () {
    $altGroup = AltGroup::query()->create(['guild_key' => 'Regenesis-Silvermoon', 'group_label' => 'g1']);
    $a = unlinkMember('Sheday-Silvermoon', ['alt_group_id' => $altGroup->id]);
    $solo = unlinkMember('Solo-Silvermoon'); // skipped

    $user = unlinkOfficer();
    $resp = $this->actingAs($user)
        ->postJson('/roster/unlink-alt/confirm', [
            'member_ids' => [$a->id, $solo->id],
            'notes' => 'Wrong group, fixing',
        ]);

    $resp->assertOk()->assertJson(['logged' => 1]);

    expect(MemberAction::query()->where('member_id', $a->id)->where('action_type', MemberAction::TYPE_UNLINK_ALT_MACRO)->exists())->toBeTrue();
    expect(MemberAction::query()->where('member_id', $solo->id)->exists())->toBeFalse();
});

it('non-officer is 403d from preview and confirm', function () {
    $u = User::factory()->create(['tier' => null, 'last_role_check_at' => now()]);
    $altGroup = AltGroup::query()->create(['guild_key' => 'Regenesis-Silvermoon', 'group_label' => 'g1']);
    $m = unlinkMember('Sheday-Silvermoon', ['alt_group_id' => $altGroup->id]);

    $this->actingAs($u)->postJson('/roster/unlink-alt', ['member_ids' => [$m->id]])->assertStatus(403);
    $this->actingAs($u)->postJson('/roster/unlink-alt/confirm', ['member_ids' => [$m->id]])->assertStatus(403);
});

it('roster row exposes the Unlink button only on alt-group members', function () {
    $altGroup = AltGroup::query()->create(['guild_key' => 'Regenesis-Silvermoon', 'group_label' => 'g1']);
    unlinkMember('Inagroup-Silvermoon', ['alt_group_id' => $altGroup->id]);
    unlinkMember('Solo-Silvermoon');

    $resp = $this->actingAs(unlinkOfficer())->get('/roster');
    $resp->assertOk();

    $body = $resp->getContent();
    expect($body)->toContain('open-unlink-alt');
});
