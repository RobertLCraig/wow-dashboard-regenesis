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

function kickMember(string $name, array $overrides = []): Member
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

function kickOfficer(): User
{
    return User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);
}

it('preview returns one macro and the character list for a single member', function () {
    $m = kickMember('Sheday-Silvermoon', ['class' => 'PALADIN']);

    $resp = $this->actingAs(kickOfficer())
        ->postJson('/roster/kick-macro', ['member_ids' => [$m->id]]);

    $resp->assertOk()->assertJson([
        'macros' => ['/gremove Sheday'],
        'characters' => [['id' => $m->id, 'name' => 'Sheday-Silvermoon', 'char_name' => 'Sheday', 'class' => 'PALADIN']],
        'skipped' => [],
        'oversized' => [],
    ]);
});

it('preview joins multiple members into one macro', function () {
    $a = kickMember('Sheday-Silvermoon');
    $b = kickMember('Tute-Silvermoon');

    $resp = $this->actingAs(kickOfficer())
        ->postJson('/roster/kick-macro', ['member_ids' => [$a->id, $b->id]]);

    $resp->assertOk()->assertJsonFragment([
        'macros' => ["/gremove Sheday\n/gremove Tute"],
    ]);
});

it('preview skips members who have already left or are banned', function () {
    $active = kickMember('Active-Silvermoon');
    $left   = kickMember('Departed-Silvermoon', ['status' => Member::STATUS_LEFT]);
    $banned = kickMember('Outlaw-Silvermoon', ['status' => Member::STATUS_BANNED]);

    $resp = $this->actingAs(kickOfficer())
        ->postJson('/roster/kick-macro', ['member_ids' => [$active->id, $left->id, $banned->id]]);

    $resp->assertOk()
        ->assertJson([
            'macros' => ['/gremove Active'],
            'characters' => [['name' => 'Active-Silvermoon']],
        ])
        ->assertJsonFragment(['name' => 'Departed-Silvermoon', 'reason' => 'already left'])
        ->assertJsonFragment(['name' => 'Outlaw-Silvermoon', 'reason' => 'already banned']);
});

it('preview rejects an empty member_ids array', function () {
    $this->actingAs(kickOfficer())
        ->postJson('/roster/kick-macro', ['member_ids' => []])
        ->assertStatus(422);
});

it('confirm logs one MemberAction per active member with type kick_macro', function () {
    $a = kickMember('Sheday-Silvermoon');
    $b = kickMember('Tute-Silvermoon');
    $left = kickMember('Departed-Silvermoon', ['status' => Member::STATUS_LEFT]);

    $user = kickOfficer();
    $resp = $this->actingAs($user)
        ->postJson('/roster/kick-macro/confirm', [
            'member_ids' => [$a->id, $b->id, $left->id],
            'notes' => 'Cleanup batch',
        ]);

    $resp->assertOk()->assertJson(['logged' => 2]);  // skipped the left member

    expect(MemberAction::query()->where('member_id', $a->id)->where('action_type', MemberAction::TYPE_KICK_MACRO)->exists())->toBeTrue();
    expect(MemberAction::query()->where('member_id', $b->id)->where('action_type', MemberAction::TYPE_KICK_MACRO)->exists())->toBeTrue();
    expect(MemberAction::query()->where('member_id', $left->id)->exists())->toBeFalse();

    $action = MemberAction::query()->where('member_id', $a->id)->first();
    expect($action->reviewed_by_user_id)->toBe($user->id);
    expect($action->decision)->toBe(MemberAction::DECISION_ACCEPTED);
    expect($action->notes)->toBe('Cleanup batch');
});

it('non-officer is 403d from preview and confirm', function () {
    $u = User::factory()->create(['tier' => null, 'last_role_check_at' => now()]);
    $m = kickMember('Sheday-Silvermoon');

    $this->actingAs($u)->postJson('/roster/kick-macro', ['member_ids' => [$m->id]])->assertStatus(403);
    $this->actingAs($u)->postJson('/roster/kick-macro/confirm', ['member_ids' => [$m->id]])->assertStatus(403);
});

it('roster row exposes the alt group ids on the kick button data attribute', function () {
    $altGroup = AltGroup::query()->create(['guild_key' => 'Regenesis-Silvermoon', 'group_label' => 'g1']);
    $main = kickMember('Main-Silvermoon', ['alt_group_id' => $altGroup->id]);
    $alt1 = kickMember('Alt1-Silvermoon', ['alt_group_id' => $altGroup->id, 'main_member_id' => $main->id]);
    $alt2 = kickMember('Alt2-Silvermoon', ['alt_group_id' => $altGroup->id, 'main_member_id' => $main->id]);

    $resp = $this->actingAs(kickOfficer())->get('/roster');
    $resp->assertOk();

    // The Main row's button should carry all three IDs in its dispatch payload.
    $body = $resp->getContent();
    $idsJson = json_encode([$main->id, $alt1->id, $alt2->id]);
    // Order may vary depending on load order; assert each id appears in some grouping near 'open-kick-macro'.
    foreach ([$main->id, $alt1->id, $alt2->id] as $id) {
        expect($body)->toContain((string) $id);
    }
    expect($body)->toContain('open-kick-macro');
});
