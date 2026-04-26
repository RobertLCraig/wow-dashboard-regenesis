<?php

use App\Models\AltGroup;
use App\Models\LogEvent;
use App\Models\Member;
use App\Models\MemberEvent;
use App\Models\MemberSnapshot;
use App\Models\Snapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'grm.guild_key' => 'Regenesis-Silvermoon',
        'grm.ingest_token' => 'test-token-32-chars-long-aaaaaaaa',
        // RefreshDatabase wraps each test in a transaction, so jobs queued
        // to the database driver in the request would be invisible to a
        // separately-spawned `queue:work` process. Sync runs them inline
        // in the same connection, which is what we want in tests.
        'queue.default' => 'sync',
    ]);
    Storage::fake('local');
});

/**
 * Helper that builds a Lua-style 1-indexed positional array. The real
 * payloads come out of LuaTableParser already 1-indexed (preserving
 * Lua's convention); these fixtures must match so the normalizer's
 * index lookups (e.g. $banned[1]) work as they would in production.
 */
function lua(array $values): array
{
    $out = [];
    foreach ($values as $i => $v) {
        $out[$i + 1] = $v;
    }
    return $out;
}

/**
 * @return string Gzipped JSON envelope ready for the ingest endpoint.
 */
function makeEnvelope(array $payloadOverrides = [], array $envelopeOverrides = []): string
{
    $defaultPayload = [
        'GRM_GuildMemberHistory_Save' => [
            'Regenesis-Silvermoon' => [
                'Totem-Silvermoon' => [
                    'GUID' => 'Player-3391-AAA',
                    'name' => 'Totem-Silvermoon',
                    'class' => 'SHAMAN', 'race' => 'Dwarf', 'level' => 80,
                    'rankName' => 'Member', 'rankIndex' => 5,
                    'lastOnline' => 24, // hours since last online (1 day)
                    'joinDateHist' => lua([
                        lua(['Member', 1, 1, 2025, '20250101', 1735689600]),
                    ]),
                    'isOnline' => false, 'isMobile' => false,
                    'achievementPoints' => 12000,
                    'note' => 'has alts',
                    'officerNote' => '',
                    'recommendToPromote' => false,
                    'recommendToDemote' => false,
                    'recommendToKick' => false,
                    'recommendSpecial' => false,
                    'altGroup' => '7',
                    'HC' => ['isDead' => false],
                    'bannedInfo' => lua([false, 0, false, '']),
                    'prof1' => lua([165, 50]),
                    'prof2' => lua([393, 75]),
                ],
                'Sindragosa-Magtheridon' => [
                    'GUID' => 'Player-3391-BBB',
                    'name' => 'Sindragosa-Magtheridon',
                    'class' => 'ROGUE', 'race' => 'BloodElf', 'level' => 80,
                    'rankName' => 'Heroic Raider', 'rankIndex' => 2,
                    'lastOnline' => 0, // currently online
                    'joinDateHist' => lua([
                        lua(['Heroic Raider', 14, 5, 2024, '20240514', 1715644800]),
                    ]),
                    'isOnline' => true,
                    'achievementPoints' => 24000,
                    'note' => '',
                    'altGroup' => '',
                    'HC' => ['isDead' => false],
                    'bannedInfo' => lua([false, 0, false, '']),
                ],
            ],
        ],
        'GRM_PlayersThatLeftHistory_Save' => [
            'Regenesis-Silvermoon' => [
                'Banhammer-Silvermoon' => [
                    'name' => 'Banhammer-Silvermoon',
                    'class' => 'WARRIOR', 'level' => 70,
                    'rankName' => 'Initiate', 'rankIndex' => 8,
                    'lastOnline' => 168, // 7 days
                    'joinDateHist' => lua([
                        lua(['Initiate', 1, 1, 2024, '20240101', 1704067200]),
                    ]),
                    'bannedInfo' => lua([true, 1714000000, true, 'griefing']),
                    'HC' => ['isDead' => false],
                ],
            ],
        ],
        'GRM_LogReport_Save' => [
            'Regenesis-Silvermoon' => lua([
                lua([
                    1,
                    "18 Feb '26 08:15pm : Officer PROMOTED Totem from Member to Heroic Raider",
                    true,
                    '|cffffffffOfficer|r',
                    '|cff0070ddTotem|r',
                    'Member',
                    'Heroic Raider',
                    lua([18, 2, 2026, 20, 15]),
                ]),
                lua([
                    14,
                    "20 Feb '26 11:00am : Sindragosa came online after 5 days",
                    '|cffeeeeooSindragosa|r',
                    '5 days',
                    lua([20, 2, 2026, 11, 0]),
                ]),
            ]),
        ],
        'GRM_Alts' => [
            'Regenesis-Silvermoon' => [
                '7' => [
                    1 => ['name' => 'Sindragosa-Magtheridon', 'class' => 'ROGUE'],
                    2 => ['name' => 'Totem-Silvermoon', 'class' => 'SHAMAN'],
                    'main' => 'Sindragosa-Magtheridon',
                    'timeModified' => 1745020800,
                    'nicknameDetails' => ['nickname' => 'Sindi', 'nickenabled' => true],
                ],
            ],
        ],
    ];

    $payload = array_replace_recursive($defaultPayload, $payloadOverrides);

    $envelope = array_replace([
        'guild_key' => 'Regenesis-Silvermoon',
        'captured_at' => now()->toIso8601String(),
        'source' => 'grm',
        'grm_version' => '1.99393',
        'payload' => $payload,
    ], $envelopeOverrides);

    return gzencode(json_encode($envelope, JSON_THROW_ON_ERROR), 9);
}

it('rejects requests without the bearer token', function () {
    $body = makeEnvelope();

    $this->call('POST', '/api/ingest/grm', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_CONTENT_ENCODING' => 'gzip',
    ], $body)
        ->assertStatus(401);
});

it('rejects requests with the wrong bearer token', function () {
    $body = makeEnvelope();

    $this->call('POST', '/api/ingest/grm', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_CONTENT_ENCODING' => 'gzip',
        'HTTP_AUTHORIZATION' => 'Bearer wrong-token',
    ], $body)
        ->assertStatus(401);
});

it('rejects payloads for the wrong guild_key', function () {
    $body = makeEnvelope([], ['guild_key' => 'SomeoneElse-Silvermoon']);

    $this->call('POST', '/api/ingest/grm', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_CONTENT_ENCODING' => 'gzip',
        'HTTP_AUTHORIZATION' => 'Bearer test-token-32-chars-long-aaaaaaaa',
    ], $body)
        ->assertStatus(422);
});

it('accepts a fresh upload, persists the snapshot, and queues the job', function () {
    Bus::fake();

    $body = makeEnvelope();

    $resp = $this->call('POST', '/api/ingest/grm', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_CONTENT_ENCODING' => 'gzip',
        'HTTP_AUTHORIZATION' => 'Bearer test-token-32-chars-long-aaaaaaaa',
    ], $body);

    $resp->assertStatus(202)->assertJson(['queued' => true]);

    expect(Snapshot::count())->toBe(1);
    $snapshot = Snapshot::first();
    expect($snapshot->guild_key)->toBe('Regenesis-Silvermoon');
    expect($snapshot->source)->toBe('grm');
    expect($snapshot->raw_path)->toContain('snapshots/Regenesis-Silvermoon/');

    Bus::assertDispatched(\App\Jobs\IngestSnapshotJob::class);
});

it('returns noop on a duplicate payload hash', function () {
    Bus::fake();

    $body = makeEnvelope();
    $headers = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_CONTENT_ENCODING' => 'gzip',
        'HTTP_AUTHORIZATION' => 'Bearer test-token-32-chars-long-aaaaaaaa',
    ];

    $this->call('POST', '/api/ingest/grm', [], [], [], $headers, $body)->assertStatus(202);
    $resp = $this->call('POST', '/api/ingest/grm', [], [], [], $headers, $body)
        ->assertStatus(200)
        ->assertJson(['noop' => true]);

    expect(Snapshot::count())->toBe(1);
});

it('runs the full pipeline end to end and persists all derived data', function () {
    $body = makeEnvelope();

    $this->call('POST', '/api/ingest/grm', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_CONTENT_ENCODING' => 'gzip',
        'HTTP_AUTHORIZATION' => 'Bearer test-token-32-chars-long-aaaaaaaa',
    ], $body)->assertStatus(202);

    // queue.default=sync runs the job inline in the request, so by the
    // time the controller returned 202 the job has already finished.

    // Members upserted: 2 active + 1 banned.
    expect(Member::count())->toBe(3);
    expect(Member::active()->count())->toBe(2);
    expect(Member::where('status', Member::STATUS_BANNED)->count())->toBe(1);

    $banned = Member::where('name', 'Banhammer-Silvermoon')->first();
    expect($banned->reason_banned)->toBe('griefing');

    // Alt group created with main + 1 alt.
    $group = AltGroup::where('group_label', '7')->first();
    expect($group)->not->toBeNull();
    expect($group->members)->toHaveCount(2);
    $main = $group->members->firstWhere('pivot.is_main', true);
    expect($main->name)->toBe('Sindragosa-Magtheridon');

    // Members linked to the group.
    expect(Member::where('alt_group_id', $group->id)->count())->toBe(2);
    $totem = Member::where('name', 'Totem-Silvermoon')->first();
    expect($totem->main_member_id)->not->toBeNull();

    // Log events stored with type names mapped.
    expect(LogEvent::count())->toBe(2);
    $promoted = LogEvent::where('type_code', 1)->first();
    expect($promoted->type_name)->toBe('PROMOTED');
    $rejoin = LogEvent::where('type_code', 14)->first();
    expect($rejoin->type_name)->toBe('INACTIVE_RETURN');

    // Member snapshots: one per member, with raw_json populated.
    expect(MemberSnapshot::count())->toBe(3);
    $sample = MemberSnapshot::first();
    expect($sample->raw_json)->toBeArray();

    // Differ on first snapshot: every active member emits a 'joined' event.
    expect(MemberEvent::where('type', MemberEvent::TYPE_JOINED)->count())->toBe(2);
});

it('detects rank changes between successive snapshots', function () {
    $headers = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_CONTENT_ENCODING' => 'gzip',
        'HTTP_AUTHORIZATION' => 'Bearer test-token-32-chars-long-aaaaaaaa',
    ];

    // Snapshot 1: initial state, Totem at rank 5 (Member).
    $this->call('POST', '/api/ingest/grm', [], [], [], $headers, makeEnvelope())->assertStatus(202);

    // Snapshot 2: Totem promoted to rank 2 (Heroic Raider).
    $this->call('POST', '/api/ingest/grm', [], [], [], $headers, makeEnvelope([
        'GRM_GuildMemberHistory_Save' => [
            'Regenesis-Silvermoon' => [
                'Totem-Silvermoon' => [
                    'rankName' => 'Heroic Raider',
                    'rankIndex' => 2,
                ],
            ],
        ],
    ]))->assertStatus(202);

    $totem = Member::where('name', 'Totem-Silvermoon')->first();
    expect($totem->rank_index)->toBe(2);

    $promotion = MemberEvent::where('member_id', $totem->id)
        ->where('type', MemberEvent::TYPE_PROMOTED)
        ->first();
    expect($promotion)->not->toBeNull();
    expect($promotion->payload_json)->toMatchArray([
        'from_rank_index' => 5,
        'to_rank_index' => 2,
    ]);
});
