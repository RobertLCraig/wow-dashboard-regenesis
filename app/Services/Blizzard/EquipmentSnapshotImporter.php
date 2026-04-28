<?php

namespace App\Services\Blizzard;

use App\Models\Member;
use App\Models\MemberEquipmentSnapshot;
use App\Models\Snapshot;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pull /profile/wow/character/.../equipment for every active member
 * and store the per-piece gear blob keyed by snapshot + member.
 *
 * Rationale: the profile summary only carries the rolled-up
 * equipped_item_level. The equipment endpoint returns the full
 * equipped_items array - one entry per slot, with item id, slot, item
 * level, enchantments, sockets, and bonus list. This is what powers
 * pre-raid readiness checks (missing enchants, empty sockets, off-spec
 * stat priorities) without needing wowaudit opt-in coverage.
 *
 * Same fan-out shape as BlizzardSnapshotImporter (Http::pool batched
 * for Hostinger's 30s wall clock), separate snapshot row stamped
 * source='blizzard_equipment'. Keeping it independent from the profile
 * importer means equipment can be pulled at a different cadence later
 * without churning shared code.
 */
class EquipmentSnapshotImporter
{
    public function __construct(
        private readonly BlizzardClient $client,
        private readonly string $guildKey,
        private readonly int $requestDelayMs = 50,
        private readonly int $minLevel = 70,
        private readonly int $concurrency = 10,
    ) {}

    /**
     * @return array{
     *   snapshot_id:int,
     *   members_queried:int,
     *   matched:int,
     *   missing:int,
     *   errored:int,
     * }
     */
    public function pull(): array
    {
        if (! $this->client->isConfigured()) {
            throw new \RuntimeException(
                'Blizzard client credentials are not configured. '
                . 'Set BLIZZARD_CLIENT_ID and BLIZZARD_CLIENT_SECRET.'
            );
        }

        $members = Member::query()
            ->forGuild($this->guildKey)
            ->active()
            ->where('level', '>=', $this->minLevel)
            ->orderBy('id')
            ->get();

        $now = CarbonImmutable::now();
        $perMemberPayloads = [];
        $matched = 0;
        $missing = 0;
        $errored = 0;

        $jobs = [];
        foreach ($members as $member) {
            $endpoint = $this->resolveEndpoint($member);
            if ($endpoint === null) {
                $errored++;
                continue;
            }
            $jobs[$member->id] = $endpoint + ['member' => $member];
        }

        $batchSize = max(1, $this->concurrency);
        $batches = array_chunk($jobs, $batchSize, preserve_keys: true);
        $timeout = $this->client->timeoutSeconds();

        foreach ($batches as $batchIndex => $batch) {
            $responses = Http::pool(function (Pool $pool) use ($batch, $timeout) {
                $reqs = [];
                foreach ($batch as $memberId => $job) {
                    $reqs[] = $pool
                        ->as((string) $memberId)
                        ->acceptJson()
                        ->timeout($timeout)
                        ->withHeaders($job['headers'])
                        ->get($job['url'], $job['query']);
                }
                return $reqs;
            });

            foreach ($batch as $memberId => $job) {
                $resp = $responses[(string) $memberId] ?? null;

                if ($resp instanceof \Throwable) {
                    Log::warning('blizzard equipment fetch failed', [
                        'member' => $job['member']->name,
                        'message' => $resp->getMessage(),
                    ]);
                    $errored++;
                    continue;
                }
                if ($resp === null) {
                    $errored++;
                    continue;
                }

                if ($resp->status() === 404) {
                    $missing++;
                    continue;
                }

                if (! $resp->successful()) {
                    Log::warning('blizzard equipment non-2xx', [
                        'member' => $job['member']->name,
                        'status' => $resp->status(),
                        'body' => mb_substr((string) $resp->body(), 0, 200),
                    ]);
                    $errored++;
                    continue;
                }

                $body = $resp->json();
                if (! is_array($body)) {
                    $errored++;
                    continue;
                }

                $perMemberPayloads[$memberId] = $body;
                $matched++;
            }

            if ($this->requestDelayMs > 0 && $batchIndex < count($batches) - 1) {
                usleep($this->requestDelayMs * 1000);
            }
        }

        ksort($perMemberPayloads);
        $payloadHash = hash('sha256', json_encode($perMemberPayloads, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($perMemberPayloads, $payloadHash, $now, $matched, $missing, $errored, $members) {
            $snapshot = Snapshot::query()->firstOrCreate(
                [
                    'guild_key' => $this->guildKey,
                    'source' => Snapshot::SOURCE_BLIZZARD_EQUIPMENT,
                    'payload_hash' => $payloadHash,
                ],
                [
                    'captured_at' => $now,
                    'member_count' => count($perMemberPayloads),
                ]
            );

            foreach ($perMemberPayloads as $memberId => $body) {
                $member = $members->firstWhere('id', $memberId);
                if (! $member) {
                    continue;
                }

                MemberEquipmentSnapshot::query()->updateOrCreate(
                    [
                        'snapshot_id' => $snapshot->id,
                        'member_id' => $member->id,
                    ],
                    [
                        'equipped_ilvl' => $this->intOrNull($body['equipped_item_level'] ?? null),
                        'average_ilvl' => $this->intOrNull($body['average_item_level'] ?? null),
                        'pieces' => is_array($body['equipped_items'] ?? null)
                            ? $body['equipped_items']
                            : [],
                    ]
                );
            }

            return [
                'snapshot_id' => $snapshot->id,
                'members_queried' => $members->count(),
                'matched' => $matched,
                'missing' => $missing,
                'errored' => $errored,
            ];
        });
    }

    /**
     * Decide what realm slug to query. Prefer the canonical
     * realm_slug column populated by the guild roster importer; fall
     * back to deriving from the legacy "Char-Realm" name plus RIO's
     * realm map for members that haven't been hit by a roster pull
     * yet (e.g. transferred-in alts visible in GRM but not yet in
     * Blizzard's cached roster snapshot).
     *
     * @return array{url:string, headers:array<string,string>, query:array<string,string>}|null
     */
    private function resolveEndpoint(Member $member): ?array
    {
        $charName = explode('-', $member->name, 2)[0] ?? null;
        if ($charName === null || $charName === '') {
            return null;
        }

        $slug = $member->realm_slug;
        if ($slug === null || $slug === '') {
            $slug = \App\Services\Raiderio\RealmSlug::slugifyCanonical($member->realm);
        }
        if ($slug === null || $slug === '') {
            $collapsed = \App\Services\Raiderio\RealmSlug::realmFromMemberName($member->name);
            $slug = \App\Services\Raiderio\RealmSlug::slugify($collapsed);
        }
        if ($slug === '') {
            return null;
        }

        return $this->client->equipmentEndpoint($slug, $charName);
    }

    private function intOrNull(mixed $v): ?int
    {
        if (! is_numeric($v) || $v <= 0) {
            return null;
        }
        return (int) round((float) $v);
    }
}
