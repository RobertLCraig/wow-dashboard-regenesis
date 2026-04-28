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

function rankMember(string $name, array $overrides = []): Member
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

function rankOfficer(): User
{
    return User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);
}

it('preview returns a /gpromote macro for a member', function () {
    $m = rankMember('Sheday-Silvermoon');

    $resp = $this->actingAs(rankOfficer())
        ->postJson('/roster/rank-macro', ['op' => 'promote', 'member_ids' => [$m->id]]);

    $resp->assertOk()->assertJson([
        'op' => 'promote',
        'macros' => ['/gpromote Sheday'],
        'characters' => [['id' => $m->id, 'name' => 'Sheday-Silvermoon', 'char_name' => 'Sheday']],
        'skipped' => [],
        'oversized' => [],
    ]);
});

it('preview returns a /gdemote macro for a member', function () {
    $m = rankMember('Sheday-Silvermoon');

    $resp = $this->actingAs(rankOfficer())
        ->postJson('/roster/rank-macro', ['op' => 'demote', 'member_ids' => [$m->id]]);

    $resp->assertOk()->assertJson([
        'op' => 'demote',
        'macros' => ['/gdemote Sheday'],
    ]);
});

it('preview rejects an unknown op', function () {
    $m = rankMember('Sheday-Silvermoon');

    $this->actingAs(rankOfficer())
        ->postJson('/roster/rank-macro', ['op' => 'lolwhat', 'member_ids' => [$m->id]])
        ->assertStatus(422);
});

it('preview rejects an empty member_ids array', function () {
    $this->actingAs(rankOfficer())
        ->postJson('/roster/rank-macro', ['op' => 'promote', 'member_ids' => []])
        ->assertStatus(422);
});

it('preview skips banned and former members', function () {
    $left = rankMember('Departed-Silvermoon', ['status' => Member::STATUS_LEFT]);
    $banned = rankMember('Outlaw-Silvermoon', ['status' => Member::STATUS_BANNED]);

    $resp = $this->actingAs(rankOfficer())
        ->postJson('/roster/rank-macro', ['op' => 'promote', 'member_ids' => [$left->id, $banned->id]]);

    $resp->assertOk()
        ->assertJsonFragment(['name' => 'Departed-Silvermoon', 'reason' => 'no longer in guild'])
        ->assertJsonFragment(['name' => 'Outlaw-Silvermoon', 'reason' => 'banned']);
});

it('confirm promote logs a MemberAction with type promote_macro', function () {
    $m = rankMember('Sheday-Silvermoon');
    $user = rankOfficer();

    $resp = $this->actingAs($user)
        ->postJson('/roster/rank-macro/confirm', [
            'op' => 'promote',
            'member_ids' => [$m->id],
            'notes' => 'Field promotion',
        ]);

    $resp->assertOk()->assertJson(['logged' => 1]);

    $action = MemberAction::query()->where('member_id', $m->id)->first();
    expect($action)->not->toBeNull();
    expect($action->action_type)->toBe(MemberAction::TYPE_PROMOTE_MACRO);
    expect($action->reviewed_by_user_id)->toBe($user->id);
    expect($action->notes)->toBe('Field promotion');
});

it('confirm demote logs a MemberAction with type demote_macro', function () {
    $m = rankMember('Sheday-Silvermoon');

    $this->actingAs(rankOfficer())
        ->postJson('/roster/rank-macro/confirm', ['op' => 'demote', 'member_ids' => [$m->id]])
        ->assertOk()
        ->assertJson(['logged' => 1]);

    expect(MemberAction::query()->where('action_type', MemberAction::TYPE_DEMOTE_MACRO)->count())->toBe(1);
});

it('non-officer is 403d from preview and confirm', function () {
    $u = User::factory()->create(['tier' => null, 'last_role_check_at' => now()]);
    $m = rankMember('Sheday-Silvermoon');

    $this->actingAs($u)->postJson('/roster/rank-macro', ['op' => 'promote', 'member_ids' => [$m->id]])->assertStatus(403);
    $this->actingAs($u)->postJson('/roster/rank-macro/confirm', ['op' => 'promote', 'member_ids' => [$m->id]])->assertStatus(403);
});

it('roster row exposes the Promote button only when recommend_promote is set', function () {
    $up = rankMember('Up-Silvermoon', ['recommend_promote' => true]);
    rankMember('Plain-Silvermoon');

    $resp = $this->actingAs(rankOfficer())->get('/roster');
    $resp->assertOk();

    $body = $resp->getContent();
    // Only the recommended row should carry an open-rank-macro dispatch
    // wired to its member id. The Plain row has no rank button.
    expect($body)->toContain("open-rank-macro', { op: 'promote', ids: [{$up->id}]");
    expect($body)->not->toContain("ids: [\" . rankMember('Plain-Silvermoon')->id . \"]"); // Plain has no rank trigger
});
