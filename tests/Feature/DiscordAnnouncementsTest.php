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
        ->toThrow(RuntimeException::class, 'not configured');
});

it('client sends the bot token + channel id on the messages endpoint', function () {
    Http::fake([
        'discord.com/api/v10/channels/channel-123/messages*' => Http::response([discordMessage()], 200),
    ]);

    DiscordAnnouncementsClient::fromConfig()->recentMessages(50);

    Http::assertSent(fn ($req) => str_contains($req->url(), 'discord.com/api/v10/channels/channel-123/messages')
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
        ->toThrow(RuntimeException::class, 'Discord channel messages fetch failed: 403');
});

function discordAttachment(array $overrides = []): array
{
    return array_replace([
        'id' => 'att-1',
        'filename' => 'roster.png',
        'content_type' => 'image/png',
        'size' => 20480,
        'width' => 1200,
        'height' => 800,
        'url' => 'https://cdn.discordapp.com/attachments/channel-123/att-1/roster.png?ex=1&is=2&hm=3',
        'proxy_url' => 'https://media.discordapp.net/attachments/channel-123/att-1/roster.png?ex=1&is=2&hm=3',
    ], $overrides);
}

it('importer upserts each message and skips empty content', function () {
    Http::fake([
        'discord.com/api/v10/channels/*' => Http::response([
            discordMessage(['id' => '1', 'content' => 'First post']),
            discordMessage(['id' => '2', 'content' => '']),  // no text, no attachments - skip
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

it('importer stores attachment urls and metadata on the row', function () {
    Http::fake([
        'discord.com/api/v10/channels/*' => Http::response([
            discordMessage(['id' => '20', 'attachments' => [discordAttachment()]]),
        ], 200),
    ]);

    (new DiscordAnnouncementsImporter(DiscordAnnouncementsClient::fromConfig()))->pull();

    $row = DiscordAnnouncement::query()->where('discord_message_id', '20')->first();
    expect($row->attachments)->toHaveCount(1);
    expect($row->attachments[0]['id'])->toBe('att-1');
    expect($row->attachments[0]['filename'])->toBe('roster.png');
    expect($row->attachments[0]['content_type'])->toBe('image/png');
    expect($row->attachments[0]['width'])->toBe(1200);
    expect($row->attachments[0]['url'])->toContain('cdn.discordapp.com');
    expect($row->attachments[0]['proxy_url'])->toContain('media.discordapp.net');
});

it('importer keeps a text-empty post that carries attachments', function () {
    Http::fake([
        'discord.com/api/v10/channels/*' => Http::response([
            discordMessage(['id' => '21', 'content' => '', 'attachments' => [discordAttachment()]]),
            discordMessage(['id' => '22', 'content' => '', 'attachments' => []]),
        ], 200),
    ]);

    $result = (new DiscordAnnouncementsImporter(DiscordAnnouncementsClient::fromConfig()))->pull();

    expect($result['imported'])->toBe(1);
    expect($result['skipped'])->toBe(1);
    expect(DiscordAnnouncement::query()->where('discord_message_id', '21')->value('content'))->toBe('');
});

it('importer drops attachment entries with no url', function () {
    Http::fake([
        'discord.com/api/v10/channels/*' => Http::response([
            discordMessage(['id' => '23', 'attachments' => [['id' => 'broken'], discordAttachment()]]),
        ], 200),
    ]);

    (new DiscordAnnouncementsImporter(DiscordAnnouncementsClient::fromConfig()))->pull();

    expect(DiscordAnnouncement::query()->where('discord_message_id', '23')->first()->attachments)->toHaveCount(1);
});

it('imageAttachments keeps only the entries Discord typed as an image', function () {
    $a = DiscordAnnouncement::query()->create([
        'discord_message_id' => '24',
        'channel_id' => 'c1',
        'author_username' => 'a',
        'content' => '',
        'attachments' => [
            discordAttachment(),
            discordAttachment(['id' => 'att-2', 'filename' => 'logs.txt', 'content_type' => 'text/plain']),
            discordAttachment(['id' => 'att-3', 'content_type' => null]),
        ],
        'posted_at' => now(),
        'fetched_at' => now(),
    ]);

    expect($a->imageAttachments())->toHaveCount(1);
    expect($a->imageAttachments()[0]['id'])->toBe('att-1');
});

it('imageAttachments is empty when the row has no attachments', function () {
    $a = new DiscordAnnouncement(['attachments' => null]);
    expect($a->imageAttachments())->toBe([]);
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
