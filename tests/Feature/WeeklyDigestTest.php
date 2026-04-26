<?php

use App\Models\Member;
use App\Models\MemberEvent;
use App\Models\MemberSnapshot;
use App\Models\Snapshot;
use App\Models\TeamMapping;
use App\Services\Digest\WeeklyDigestBuilder;
use App\Services\Discord\DiscordWebhookPoster;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'grm.guild_key' => 'Regenesis-Silvermoon',
        'digest.discord_webhook_url' => 'https://discord.test/webhooks/123/abc',
        'digest.timeout' => 5,
    ]);
});

function digestMember(string $name, array $overrides = []): Member
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

it('digest includes roster delta over the past 7 days', function () {
    $now = CarbonImmutable::now();
    $alice = digestMember('Alice-Silvermoon');
    $bob = digestMember('Bob-Silvermoon');

    MemberEvent::query()->create([
        'member_id' => $alice->id, 'type' => MemberEvent::TYPE_JOINED,
        'occurred_at' => $now->subDays(2), 'dedup_hash' => 'h1',
    ]);
    MemberEvent::query()->create([
        'member_id' => $bob->id, 'type' => MemberEvent::TYPE_LEFT,
        'occurred_at' => $now->subDays(3), 'dedup_hash' => 'h2',
    ]);
    // Old event - outside the 7-day window, should not count.
    MemberEvent::query()->create([
        'member_id' => $alice->id, 'type' => MemberEvent::TYPE_JOINED,
        'occurred_at' => $now->subDays(20), 'dedup_hash' => 'h3',
    ]);

    $built = (new WeeklyDigestBuilder('Regenesis-Silvermoon', $now))->build();

    expect($built['data']['roster']['joined'])->toBe(1);
    expect($built['data']['roster']['left'])->toBe(1);
    expect($built['data']['roster']['delta'])->toBe(0);
    expect($built['markdown'])->toContain('+1 / -1');
});

it('digest includes anniversaries falling in the current week', function () {
    $now = CarbonImmutable::now();
    $weekStart = $now->startOfWeek();

    $m = digestMember('Veteran-Silvermoon', ['join_date' => $now->subYears(3)->toDateString()]);
    MemberEvent::query()->create([
        'member_id' => $m->id, 'type' => MemberEvent::TYPE_ANNIVERSARY,
        'occurred_at' => $weekStart->addDays(2), 'dedup_hash' => 'a1',
    ]);

    $built = (new WeeklyDigestBuilder('Regenesis-Silvermoon', $now))->build();

    expect($built['markdown'])
        ->toContain('Anniversaries')
        ->toContain('Veteran-Silvermoon')
        ->toContain('(3y)');
});

it('digest reports newly inactive members crossing the 30/60/90d boundaries', function () {
    $now = CarbonImmutable::now();

    digestMember('Just30-Silvermoon', ['last_online_at' => $now->subDays(33)]);  // newly past 30d
    digestMember('Long60-Silvermoon', ['last_online_at' => $now->subDays(64)]);  // newly past 60d
    digestMember('Active-Silvermoon', ['last_online_at' => $now->subDay()]);     // not inactive

    $built = (new WeeklyDigestBuilder('Regenesis-Silvermoon', $now))->build();

    expect($built['markdown'])
        ->toContain('Newly inactive')
        ->toContain('Just30-Silvermoon')
        ->toContain('Long60-Silvermoon')
        ->not->toContain('Active-Silvermoon');
});

it('digest summarises team progression and the top RIO scores', function () {
    $now = CarbonImmutable::now();
    $a = digestMember('Mythraider-Silvermoon', ['team' => TeamMapping::TEAM_MYTHIC]);
    $b = digestMember('Heroraider-Silvermoon', ['team' => TeamMapping::TEAM_HEROIC]);

    $snap = Snapshot::query()->create([
        'guild_key' => 'Regenesis-Silvermoon',
        'captured_at' => $now->subDay(),
        'source' => Snapshot::SOURCE_RAIDERIO,
        'payload_hash' => 'h',
    ]);
    MemberSnapshot::query()->create([
        'snapshot_id' => $snap->id, 'member_id' => $a->id,
        'ilvl' => 660, 'mplus_score' => 2400.0, 'mplus_keystone' => 18,
        'raid_progression_json' => ['manaforge-omega' => ['summary' => '8/8 H 5/8 M', 'mythic_bosses_killed' => 5, 'heroic_bosses_killed' => 8]],
    ]);
    MemberSnapshot::query()->create([
        'snapshot_id' => $snap->id, 'member_id' => $b->id,
        'ilvl' => 645, 'mplus_score' => 1500.0, 'mplus_keystone' => 14,
        'raid_progression_json' => ['manaforge-omega' => ['summary' => '8/8 H', 'mythic_bosses_killed' => 0, 'heroic_bosses_killed' => 8]],
    ]);

    $built = (new WeeklyDigestBuilder('Regenesis-Silvermoon', $now))->build();

    expect($built['markdown'])
        ->toContain('Team progression')
        ->toContain('Mythic: 1 members')
        ->toContain('8/8 H 5/8 M')
        ->toContain('Heroic: 1 members')
        ->toContain('Top M+ scores')
        ->toContain('Mythraider-Silvermoon')
        ->toContain('2,400');
});

it('builder produces markdown even with an empty guild', function () {
    $built = (new WeeklyDigestBuilder('Regenesis-Silvermoon'))->build();
    expect($built['markdown'])
        ->toContain('Regenesis weekly digest')
        ->toContain('0 active');
});

// --- DiscordWebhookPoster -------------------------------------------

it('poster posts the body to the webhook URL as JSON', function () {
    Http::fake(['discord.test/*' => Http::response('', 204)]);

    $r = (new DiscordWebhookPoster('https://discord.test/webhooks/1/abc'))->post('hi from regenesis');

    expect($r['posted'])->toBe(1);
    Http::assertSent(fn ($req) =>
        $req->url() === 'https://discord.test/webhooks/1/abc'
        && $req['content'] === 'hi from regenesis'
    );
});

it('poster splits a >2000 char body on the nearest preceding newline', function () {
    Http::fake(['discord.test/*' => Http::response('', 204)]);

    // Build something safely over 2000 chars but with newlines we can split on.
    $line = str_repeat('a', 100);
    $body = implode("\n", array_fill(0, 25, $line));  // 25 * (100 + 1) - 1 = 2524 chars

    $r = (new DiscordWebhookPoster('https://discord.test/wh'))->post($body);

    expect($r['posted'])->toBe(2);
    foreach (Http::recorded() as [$req]) {
        expect(strlen($req['content']))->toBeLessThanOrEqual(2000);
    }
});

it('poster reports a non-2xx response as an error and stops', function () {
    Http::fake(['discord.test/*' => Http::response('rate limited', 429)]);

    $r = (new DiscordWebhookPoster('https://discord.test/wh'))->post('hi');

    expect($r['posted'])->toBe(0);
    expect($r['status'])->toBe(429);
    expect($r['error'])->toContain('429');
});

it('poster short-circuits when no webhook URL is configured', function () {
    $r = (new DiscordWebhookPoster(''))->post('hi');
    expect($r)->toBe(['posted' => 0, 'status' => null, 'error' => 'webhook URL not configured']);
});

// --- Command --------------------------------------------------------

it('digest:weekly --dry-run prints to stdout without posting', function () {
    Http::fake();
    digestMember('Sheday-Silvermoon');

    $this->artisan('digest:weekly', ['--dry-run' => true])
        ->expectsOutputToContain('DIGEST PREVIEW')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

it('digest:weekly posts to Discord when the webhook is set', function () {
    Http::fake(['discord.test/*' => Http::response('', 204)]);
    digestMember('Sheday-Silvermoon');

    $this->artisan('digest:weekly')
        ->expectsOutputToContain('Posted weekly digest')
        ->assertExitCode(0);

    Http::assertSent(fn ($req) => str_contains($req->url(), 'discord.test/webhooks'));
});

it('digest:weekly falls back to stdout when no webhook is configured', function () {
    config(['digest.discord_webhook_url' => '']);
    Http::fake();
    digestMember('Sheday-Silvermoon');

    $this->artisan('digest:weekly')
        ->expectsOutputToContain('not set; printing to stdout')
        ->assertExitCode(0);

    Http::assertNothingSent();
});
