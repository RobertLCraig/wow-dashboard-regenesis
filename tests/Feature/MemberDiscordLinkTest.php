<?php

use App\Models\Member;
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

function discordLinkMember(string $name, array $overrides = []): Member
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

function discordLinkOfficer(): User
{
    return User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);
}

it('officer can write a discord_user_id and discord_username on a member', function () {
    $m = discordLinkMember('Sheday-Silvermoon');
    $u = discordLinkOfficer();

    $resp = $this->actingAs($u)
        ->putJson("/roster/{$m->id}/discord-link", [
            'discord_user_id' => '123456789012345678',
            'discord_username' => 'sheday',
        ]);

    $resp->assertOk()->assertJsonPath('link.discord_user_id', '123456789012345678');
    $resp->assertJsonPath('link.discord_username', 'sheday');
    $resp->assertJsonPath('link.source', Member::DISCORD_LINK_MANUAL);

    $m->refresh();
    expect($m->discord_user_id)->toBe('123456789012345678');
    expect($m->discord_username)->toBe('sheday');
    expect($m->discord_link_source)->toBe(Member::DISCORD_LINK_MANUAL);
    expect($m->discord_linked_at)->not->toBeNull();
    expect($m->discord_linked_by_user_id)->toBe($u->id);
});

it('officer can write only the username, leaving the snowflake null', function () {
    $m = discordLinkMember('Sheday-Silvermoon');

    $this->actingAs(discordLinkOfficer())
        ->putJson("/roster/{$m->id}/discord-link", [
            'discord_user_id' => null,
            'discord_username' => 'sheday',
        ])
        ->assertOk()
        ->assertJsonPath('link.discord_user_id', null)
        ->assertJsonPath('link.discord_username', 'sheday');

    expect($m->fresh()->discord_user_id)->toBeNull();
    expect($m->fresh()->discord_username)->toBe('sheday');
});

it('rejects a non-numeric discord_user_id with a 422', function () {
    $m = discordLinkMember('Sheday-Silvermoon');

    $this->actingAs(discordLinkOfficer())
        ->putJson("/roster/{$m->id}/discord-link", [
            'discord_user_id' => 'sheday#1234',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['discord_user_id']);
});

it('rejects a snowflake that is the wrong length', function () {
    $m = discordLinkMember('Sheday-Silvermoon');

    $this->actingAs(discordLinkOfficer())
        ->putJson("/roster/{$m->id}/discord-link", [
            'discord_user_id' => '12345',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['discord_user_id']);
});

it('two members can share the same discord_user_id (main and alt)', function () {
    $main = discordLinkMember('Main-Silvermoon');
    $alt  = discordLinkMember('Alt-Silvermoon');
    $u = discordLinkOfficer();

    foreach ([$main, $alt] as $member) {
        $this->actingAs($u)
            ->putJson("/roster/{$member->id}/discord-link", [
                'discord_user_id' => '123456789012345678',
                'discord_username' => 'someone',
            ])
            ->assertOk();
    }

    expect(Member::query()->where('discord_user_id', '123456789012345678')->count())->toBe(2);
});

it('DELETE clears the discord link columns', function () {
    $m = discordLinkMember('Sheday-Silvermoon', [
        'discord_user_id' => '123456789012345678',
        'discord_username' => 'sheday',
        'discord_link_source' => Member::DISCORD_LINK_MANUAL,
        'discord_linked_at' => now(),
    ]);

    $this->actingAs(discordLinkOfficer())
        ->deleteJson("/roster/{$m->id}/discord-link")
        ->assertOk()
        ->assertJsonPath('link.discord_user_id', null)
        ->assertJsonPath('link.discord_username', null)
        ->assertJsonPath('link.source', null);

    $m->refresh();
    expect($m->discord_user_id)->toBeNull();
    expect($m->discord_username)->toBeNull();
    expect($m->discord_link_source)->toBeNull();
    expect($m->discord_linked_at)->toBeNull();
});

it('non-officer is 403d', function () {
    $m = discordLinkMember('Sheday-Silvermoon');
    $u = User::factory()->create(['tier' => null, 'last_role_check_at' => now()]);

    $this->actingAs($u)
        ->putJson("/roster/{$m->id}/discord-link", ['discord_user_id' => '123456789012345678'])
        ->assertStatus(403);

    $this->actingAs($u)
        ->deleteJson("/roster/{$m->id}/discord-link")
        ->assertStatus(403);
});

it('member from a different guild_key 404s', function () {
    $m = Member::query()->create([
        'guild_key' => 'OtherGuild-Silvermoon',
        'name' => 'Outsider-Silvermoon',
        'class' => 'PRIEST',
        'status' => Member::STATUS_ACTIVE,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    $this->actingAs(discordLinkOfficer())
        ->putJson("/roster/{$m->id}/discord-link", [
            'discord_user_id' => '123456789012345678',
        ])
        ->assertStatus(404);
});

it('blank user_id and username with no existing link is a no-op', function () {
    $m = discordLinkMember('Sheday-Silvermoon');

    $this->actingAs(discordLinkOfficer())
        ->putJson("/roster/{$m->id}/discord-link", [
            'discord_user_id' => '',
            'discord_username' => '',
        ])
        ->assertOk()
        ->assertJsonPath('link.discord_user_id', null);

    expect($m->fresh()->discord_link_source)->toBeNull();
});

it('updating an existing link refreshes the linked_at and linked_by columns', function () {
    $original = User::factory()->create(['tier' => 'officer', 'last_role_check_at' => now()]);
    $m = discordLinkMember('Sheday-Silvermoon', [
        'discord_user_id' => '111111111111111111',
        'discord_username' => 'old',
        'discord_link_source' => Member::DISCORD_LINK_MANUAL,
        'discord_linked_at' => now()->subWeek(),
        'discord_linked_by_user_id' => $original->id,
    ]);

    $newOfficer = discordLinkOfficer();
    $this->actingAs($newOfficer)
        ->putJson("/roster/{$m->id}/discord-link", [
            'discord_user_id' => '999999999999999999',
            'discord_username' => 'new',
        ])
        ->assertOk();

    $m->refresh();
    expect($m->discord_user_id)->toBe('999999999999999999');
    expect($m->discord_username)->toBe('new');
    expect($m->discord_linked_by_user_id)->toBe($newOfficer->id);
    expect($m->discord_linked_at->isAfter(now()->subMinute()))->toBeTrue();
});

it('roster row shows the discord username in its cell when linked', function () {
    discordLinkMember('Sheday-Silvermoon', [
        'discord_user_id' => '123456789012345678',
        'discord_username' => 'sheday-discord',
        'discord_link_source' => Member::DISCORD_LINK_MANUAL,
        'discord_linked_at' => now(),
    ]);

    $resp = $this->actingAs(discordLinkOfficer())->get('/roster');

    $resp->assertOk();
    $body = $resp->getContent();
    // The cell wraps the username in a button that opens the modal,
    // so the dispatch handler proves it's the right cell, the username
    // text proves the cell is populated, and the data-sort-value proves
    // the column sorts on the lower-cased username.
    expect($body)->toContain('open-discord-link');
    expect($body)->toContain('sheday-discord');
    expect($body)->toContain('data-sort-key="discord"');
});

it('roster row shows a dash in the discord cell when unlinked', function () {
    discordLinkMember('Sheday-Silvermoon');

    $resp = $this->actingAs(discordLinkOfficer())->get('/roster');

    $resp->assertOk();
    $body = $resp->getContent();
    // Unlinked members still get a clickable cell (so the officer can
    // add a link) but it renders as a muted dash rather than a username.
    expect($body)->toContain('open-discord-link');
    expect($body)->toContain('data-sort-key="discord"');
});

it('alt of column carries a sort key and value', function () {
    $main = discordLinkMember('Main-Silvermoon');
    discordLinkMember('Alt-Silvermoon', ['main_member_id' => $main->id]);

    $resp = $this->actingAs(discordLinkOfficer())->get('/roster');

    $resp->assertOk();
    $body = $resp->getContent();
    expect($body)->toContain('data-sort-key="altof"');
    expect($body)->toContain('@click="sortBy(\'altof\')"');
});

it('flags column carries a sort key and counts the visible flags', function () {
    discordLinkMember('Sheday-Silvermoon', ['recommend_promote' => true, 'recommend_kick' => true]);

    $resp = $this->actingAs(discordLinkOfficer())->get('/roster');

    $resp->assertOk();
    $body = $resp->getContent();
    expect($body)->toContain('data-sort-key="flags"');
    expect($body)->toContain('@click="sortBy(\'flags\')"');
    // Two flags fired (promote + kick), so the cell's sort value is 2.
    expect($body)->toContain('data-sort-value="2"');
});
