<?php

use App\Models\DiscordAnnouncement;
use App\Services\Discord\DiscordAnnouncementsClient;
use App\Services\Discord\DiscordAnnouncementsImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'discord.guild_id' => '1247256415542841416',
        'discord.bot_token' => 'test-bot-token',
        'discord.announcements_channel_id' => 'channel-123',
        'discord.http_timeout' => 5,
        'discord.announcements_pull_limit' => 10,
        'discord.announcements_window_days' => 30,
    ]);
});

function discordMessage(array $overrides = []): array
{
    return array_replace([
        'id' => '900000000000000000',
        'channel_id' => 'channel-123',
        'content' => 'Transmog contest tonight at 21:00 UK!',
        'author' => ['id' => 'u1', 'username' => 'GuildHerald'],
        'timestamp' => '2026-04-26T19:30:00.000000+00:00',
    ], $overrides);
}

it('isConfigured tracks whether bot token and channel id are both present', function () {
    expect(DiscordAnnouncementsClient::fromConfig()->isConfigured())->toBeTrue();

    config(['discord.bot_token' => '']);
    expect(DiscordAnnouncementsClient::fromConfig()->isConfigured())->toBeFalse();

    config(['discord.bot_token' => 'x', 'discord.announcements_channel_id' => '']);
    expect(DiscordAnnouncementsClient::fromConfig()->isConfigured())->toBeFalse();
});

it('client throws when bot token / channel id are missing', function () {
    config(['discord.bot_token' => '', 'discord.announcements_channel_id' => '']);
    expect(fn () => DiscordAnnouncementsClient::fromConfig()->recentMessages())
        ->toThrow(\RuntimeException::class, 'not configured');
});

it('client sends the bot token + channel id on the messages endpoint', function () {
    Http::fake([
        'discord.com/api/v10/channels/channel-123/messages*' => Http::response([discordMessage()], 200),
    ]);

    DiscordAnnouncementsClient::fromConfig()->recentMessages(50);

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), 'discord.com/api/v10/channels/channel-123/messages')
        && $req->hasHeader('Authorization', 'Bot test-bot-token')
        && str_contains($req->url(), 'limit=50')
    );
});

it('client clamps the limit to the Discord-supported range', function () {
    Http::fake([
        'discord.com/api/v10/channels/channel-123/messages*' => Http::response([], 200),
    ]);

    DiscordAnnouncementsClient::fromConfig()->recentMessages(500);
    Http::assertSent(fn ($req) => str_contains($req->url(), 'limit=100'));
});

it('client throws on a non-2xx response', function () {
    Http::fake([
        'discord.com/api/v10/channels/*' => Http::response(['message' => 'forbidden'], 403),
    ]);

    expect(fn () => DiscordAnnouncementsClient::fromConfig()->recentMessages())
        ->toThrow(\RuntimeException::class, 'Discord channel messages fetch failed: 403');
});

it('importer upserts each message and skips empty content', function () {
    Http::fake([
        'discord.com/api/v10/channels/*' => Http::response([
            discordMessage(['id' => '1', 'content' => 'First post']),
            discordMessage(['id' => '2', 'content' => '']),  // empty (image-only) - skip
            discordMessage(['id' => '3', 'content' => 'Third post']),
        ], 200),
    ]);

    $result = (new DiscordAnnouncementsImporter(DiscordAnnouncementsClient::fromConfig()))->pull();

    expect($result['imported'])->toBe(2);
    expect($result['skipped'])->toBe(1);
    expect($result['total_seen'])->toBe(3);
    expect(DiscordAnnouncement::query()->count())->toBe(2);
    expect(DiscordAnnouncement::query()->where('discord_message_id', '1')->value('content'))->toBe('First post');
});

it('importer is idempotent on a re-pull (upserts in place)', function () {
    Http::fake([
        'discord.com/api/v10/channels/*' => Http::response([discordMessage(['id' => '5'])], 200),
    ]);

    $importer = new DiscordAnnouncementsImporter(DiscordAnnouncementsClient::fromConfig());
    $importer->pull();
    $importer->pull();

    expect(DiscordAnnouncement::query()->count())->toBe(1);
});

it('importer captures author username + posted_at + guild_id from config', function () {
    Http::fake([
        'discord.com/api/v10/channels/*' => Http::response([
            discordMessage(['id' => '10']),
        ], 200),
    ]);

    (new DiscordAnnouncementsImporter(DiscordAnnouncementsClient::fromConfig()))->pull();

    $row = DiscordAnnouncement::query()->where('discord_message_id', '10')->first();
    expect($row)->not->toBeNull();
    expect($row->author_username)->toBe('GuildHerald');
    expect($row->posted_at?->toIso8601String())->toContain('2026-04-26T19:30:00');
    expect($row->guild_id)->toBe('1247256415542841416');
});

it('discord:fetch-announcements short-circuits cleanly when not configured', function () {
    config(['discord.bot_token' => '', 'discord.announcements_channel_id' => '']);

    $this->artisan('discord:fetch-announcements')
        ->expectsOutputToContain('discord:fetch-announcements skipped')
        ->assertExitCode(0);
});

it('discord:fetch-announcements runs end-to-end and reports counts', function () {
    Http::fake([
        'discord.com/api/v10/channels/*' => Http::response([discordMessage(), discordMessage(['id' => 'm2'])], 200),
    ]);

    $this->artisan('discord:fetch-announcements')
        ->expectsOutputToContain('2 imported')
        ->assertExitCode(0);

    expect(DiscordAnnouncement::query()->count())->toBe(2);
});

it('DiscordAnnouncement::discordUrl uses guild + channel + message ids', function () {
    $a = DiscordAnnouncement::query()->create([
        'discord_message_id' => '99',
        'guild_id' => 'g1',
        'channel_id' => 'c1',
        'author_username' => 'a',
        'content' => 'x',
        'posted_at' => now(),
        'fetched_at' => now(),
    ]);
    expect($a->discordUrl())->toBe('https://discord.com/channels/g1/c1/99');
});
