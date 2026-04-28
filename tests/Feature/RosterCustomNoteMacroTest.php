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

function noteMember(string $name, array $overrides = []): Member
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

function noteOfficer(): User
{
    return User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);
}

it('preview returns the character + the EditCustomNote macro', function () {
    $m = noteMember('Sheday-Silvermoon', ['custom_note' => 'existing GRM note']);

    $resp = $this->actingAs(noteOfficer())
        ->postJson('/roster/custom-note', [
            'member_id' => $m->id,
            'note' => 'New note',
            'replace' => true,
        ]);

    $resp->assertOk()->assertJson([
        'character' => [
            'id' => $m->id,
            'name' => 'Sheday-Silvermoon',
            'current_note' => 'existing GRM note',
        ],
        'macro' => '/run GRM_API.EditCustomNote("Sheday-Silvermoon","New note",true,false)',
    ]);
});

it('preview rejects a member that is not active', function () {
    $left = noteMember('Departed-Silvermoon', ['status' => Member::STATUS_LEFT]);

    $this->actingAs(noteOfficer())
        ->postJson('/roster/custom-note', [
            'member_id' => $left->id,
            'note' => 'note',
            'replace' => true,
        ])
        ->assertStatus(422);
});

it('preview rejects a note longer than 150 chars', function () {
    $m = noteMember('Sheday-Silvermoon');

    $this->actingAs(noteOfficer())
        ->postJson('/roster/custom-note', [
            'member_id' => $m->id,
            'note' => str_repeat('x', 151),
            'replace' => true,
        ])
        ->assertStatus(422);
});

it('confirm logs a MemberAction with type custom_note_macro and the new note', function () {
    $m = noteMember('Sheday-Silvermoon');
    $user = noteOfficer();

    $resp = $this->actingAs($user)
        ->postJson('/roster/custom-note/confirm', [
            'member_id' => $m->id,
            'note' => 'Tank trial week 1',
            'replace' => true,
            'notes' => 'discussed in officer chat',
        ]);

    $resp->assertOk()->assertJson(['logged' => 1]);

    $action = MemberAction::query()->where('member_id', $m->id)->first();
    expect($action)->not->toBeNull();
    expect($action->action_type)->toBe(MemberAction::TYPE_CUSTOM_NOTE_MACRO);
    expect($action->reviewed_by_user_id)->toBe($user->id);
    expect($action->notes)->toContain('replace');
    expect($action->notes)->toContain('Tank trial week 1');
    expect($action->notes)->toContain('discussed in officer chat');
});

it('confirm rejects a member that has left', function () {
    $left = noteMember('Departed-Silvermoon', ['status' => Member::STATUS_LEFT]);

    $this->actingAs(noteOfficer())
        ->postJson('/roster/custom-note/confirm', [
            'member_id' => $left->id,
            'note' => 'note',
            'replace' => true,
        ])
        ->assertStatus(422);
});

it('non-officer is 403d from preview and confirm', function () {
    $u = User::factory()->create(['tier' => null, 'last_role_check_at' => now()]);
    $m = noteMember('Sheday-Silvermoon');

    $this->actingAs($u)->postJson('/roster/custom-note', ['member_id' => $m->id, 'note' => 'x', 'replace' => true])->assertStatus(403);
    $this->actingAs($u)->postJson('/roster/custom-note/confirm', ['member_id' => $m->id, 'note' => 'x', 'replace' => true])->assertStatus(403);
});

it('roster row exposes the Note button on every active member', function () {
    $m1 = noteMember('Sheday-Silvermoon');
    $m2 = noteMember('Plain-Silvermoon');

    $resp = $this->actingAs(noteOfficer())->get('/roster');
    $resp->assertOk();

    $body = $resp->getContent();
    expect($body)->toContain('open-custom-note');
    // Both rows should have the trigger.
    expect($body)->toContain("id: {$m1->id}");
    expect($body)->toContain("id: {$m2->id}");
});
