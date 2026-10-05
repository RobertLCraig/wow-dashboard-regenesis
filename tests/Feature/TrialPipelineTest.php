<?php

use App\Models\DiscordRecruitForm;
use App\Models\Member;
use App\Models\MemberEvent;
use App\Models\MemberTeam;
use App\Models\TeamMapping;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['grm.guild_key' => 'Regenesis-Silvermoon']);
});

function pipelineMember(string $name, array $teams = [], array $overrides = []): Member
{
    $member = Member::query()->create(array_replace([
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

    foreach ($teams as $team) {
        MemberTeam::query()->create(['member_id' => $member->id, 'team' => $team, 'is_override' => false]);
    }

    return $member;
}

function pipelineEvent(Member $member, string $type, ?string $team, $at): MemberEvent
{
    return MemberEvent::query()->create([
        'member_id' => $member->id,
        'type' => $type,
        'payload_json' => $team !== null ? ['team' => $team, 'via' => 'rank', 'user_id' => null] : [],
        'occurred_at' => $at,
    ]);
}

function pipelineForm(string $character, $postedAt): DiscordRecruitForm
{
    static $thread = 1000;

    return DiscordRecruitForm::query()->create([
        'discord_thread_id' => (string) $thread++,
        'channel_id' => '1',
        'thread_title' => "Character: {$character} | Discord: someone",
        'discord_username' => 'someone',
        'character_name' => $character,
        'posted_at' => $postedAt,
        'fetched_at' => now(),
    ]);
}

function pipelinePage(): string
{
    $officer = User::factory()->create(['tier' => User::TIER_OFFICER, 'last_role_check_at' => now()]);

    return test()->actingAs($officer)->get('/roster/pipeline')->assertOk()->getContent();
}

/** The HTML of one stage column, so a test can say which column a person is in. */
function pipelineColumn(string $html, string $stage): string
{
    preg_match('/<section[^>]*data-stage="'.$stage.'".*?<\/section>/s', $html, $m);

    return $m[0] ?? '';
}

it('shows the four pipeline stages with counts', function () {
    pipelineForm('Newbie', now()->subDays(3));
    pipelineMember('Tryhard-Silvermoon', [TeamMapping::TEAM_MYTHIC_TRIAL]);
    pipelineMember('Steady-Silvermoon', [TeamMapping::TEAM_HEROIC]);
    pipelineMember('Veteran-Silvermoon', [TeamMapping::TEAM_MYTHIC]);

    $html = pipelinePage();

    foreach (['applied' => 'Applied', 'trial' => 'Trial', 'raider' => 'Raider', 'alumni' => 'Alumni'] as $stage => $label) {
        expect(pipelineColumn($html, $stage))->toContain($label);
    }
    expect($html)
        ->toContain('<span data-count="applied">1</span>')
        ->toContain('<span data-count="trial">1</span>')
        ->toContain('<span data-count="raider">2</span>')
        ->toContain('<span data-count="alumni">0</span>');
});

it('shows how long a trial has been running', function () {
    $timed = pipelineMember('Timed-Silvermoon', [TeamMapping::TEAM_MYTHIC_TRIAL]);
    pipelineEvent($timed, MemberEvent::TYPE_TEAM_JOINED, TeamMapping::TEAM_MYTHIC_TRIAL, now()->subDays(40));
    pipelineEvent($timed, MemberEvent::TYPE_TEAM_LEFT, TeamMapping::TEAM_MYTHIC_TRIAL, now()->subDays(30));
    pipelineEvent($timed, MemberEvent::TYPE_TEAM_JOINED, TeamMapping::TEAM_MYTHIC_TRIAL, now()->subDays(12));
    // A later join to another team is not this trial's start.
    pipelineEvent($timed, MemberEvent::TYPE_TEAM_JOINED, TeamMapping::TEAM_HEROIC, now()->subDays(2));
    pipelineMember('Oldtimer-Silvermoon', [TeamMapping::TEAM_HEROIC_TRIAL]);

    $trial = pipelineColumn(pipelinePage(), 'trial');

    expect($trial)->toMatch('/Timed-Silvermoon.*?12 days/s')
        ->and($trial)->toMatch('/Oldtimer-Silvermoon.*?since before records/s');
});

it('puts raid team members under raider', function () {
    pipelineMember('Mythicraider-Silvermoon', [TeamMapping::TEAM_MYTHIC]);
    pipelineMember('Heroicraider-Silvermoon', [TeamMapping::TEAM_HEROIC]);
    pipelineMember('Both-Silvermoon', [TeamMapping::TEAM_HEROIC, TeamMapping::TEAM_MYTHIC_TRIAL]);
    pipelineMember('Gone-Silvermoon', [TeamMapping::TEAM_MYTHIC], ['status' => Member::STATUS_LEFT]);

    $html = pipelinePage();
    $raider = pipelineColumn($html, 'raider');

    expect($raider)->toContain('Mythicraider-Silvermoon')
        ->toContain('Heroicraider-Silvermoon')
        ->not->toContain('Both-Silvermoon')
        ->not->toContain('Gone-Silvermoon')
        ->and(pipelineColumn($html, 'trial'))->toContain('Both-Silvermoon');
});

it('lists a departed trial or raider under alumni', function () {
    $left = pipelineMember('Leaver-Silvermoon', [], ['status' => Member::STATUS_LEFT]);
    pipelineEvent($left, MemberEvent::TYPE_TEAM_JOINED, TeamMapping::TEAM_HEROIC_TRIAL, '2026-08-01 20:00:00');
    pipelineEvent($left, MemberEvent::TYPE_TEAM_JOINED, TeamMapping::TEAM_HEROIC, '2026-08-20 20:00:00');
    pipelineEvent($left, MemberEvent::TYPE_LEFT, null, '2026-09-14 10:00:00');

    $kicked = pipelineMember('Booted-Silvermoon', [], ['status' => Member::STATUS_BANNED]);
    pipelineEvent($kicked, MemberEvent::TYPE_TEAM_JOINED, TeamMapping::TEAM_MYTHIC_TRIAL, '2026-09-01 20:00:00');
    pipelineEvent($kicked, MemberEvent::TYPE_KICKED, null, '2026-09-20 10:00:00');
    $kicked->delete(); // soft-deleted rows still count

    pipelineMember('Neverraided-Silvermoon', [], ['status' => Member::STATUS_LEFT]);

    $alumni = pipelineColumn(pipelinePage(), 'alumni');

    expect($alumni)->toMatch('/Leaver-Silvermoon.*?Heroic Team.*?2026-09-14/s')
        ->and($alumni)->toMatch('/Booted-Silvermoon.*?Mythic Trial Team.*?2026-09-20/s')
        ->and($alumni)->not->toContain('Neverraided-Silvermoon');
});

it('lists a recruit form with no team under applied', function () {
    pipelineForm('Hopeful', '2026-10-01 18:00:00');
    pipelineForm('Signedup', now()->subDays(5));
    pipelineMember('Signedup-Silvermoon', [TeamMapping::TEAM_HEROIC_TRIAL]);
    pipelineForm('Ancient', now()->subDays(61));

    $applied = pipelineColumn(pipelinePage(), 'applied');

    expect($applied)->toMatch('/Hopeful.*?2026-10-01/s')
        ->not->toContain('Signedup')
        ->not->toContain('Ancient');
});

it('shows a person\'s team history', function () {
    $m = pipelineMember('Historic-Silvermoon', [TeamMapping::TEAM_MYTHIC]);
    // Written newest first, so an unordered read shows the wrong order.
    pipelineEvent($m, MemberEvent::TYPE_TEAM_JOINED, TeamMapping::TEAM_MYTHIC, '2026-09-10 20:00:00');
    pipelineEvent($m, MemberEvent::TYPE_TEAM_LEFT, TeamMapping::TEAM_MYTHIC_TRIAL, '2026-09-10 20:00:00');
    pipelineEvent($m, MemberEvent::TYPE_TEAM_JOINED, TeamMapping::TEAM_MYTHIC_TRIAL, '2026-08-01 20:00:00');
    pipelineEvent($m, MemberEvent::TYPE_PROMOTED, null, '2026-08-05 20:00:00');

    $raider = pipelineColumn(pipelinePage(), 'raider');

    expect($raider)->toMatch('/<details.*?Historic-Silvermoon.*?2026-08-01.*?Joined Mythic Trial Team.*?2026-09-10.*?Joined Mythic Team.*?<\/details>/s')
        ->toContain('Left Mythic Trial Team')
        ->not->toContain('2026-08-05');
});

it('refuses the pipeline page to a member', function () {
    $member = User::factory()->create(['tier' => User::TIER_MEMBER, 'last_role_check_at' => now()]);

    $this->actingAs($member)->get('/roster/pipeline')->assertForbidden();
});
