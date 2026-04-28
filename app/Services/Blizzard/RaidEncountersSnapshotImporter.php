<?php

namespace App\Services\Blizzard;

use App\Models\Member;
use App\Models\MemberRaidSnapshot;
use App\Models\Snapshot;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pull /profile/wow/character/.../encounters/raids for every active
 * member.
 *
 * Stores the full expansions[] tree per character. Reads (AOTC/CE
 * detection, last-kill recency, team-level progression rollups) parse
 * the JSON on demand so we're not coupled to the current expansion's
 * raid layout at write time.
 *
 * Same fan-out shape as the other Blizzard per-character importers.
 * 404 covers fresh chars who have never zoned a raid; treated as
 * missing not error.
 */
class RaidEncountersSnapshotImporter
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
                    Log::warning('blizzard raids fetch failed', [
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
                    Log::warning('blizzard raids non-2xx', [
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
                    'source' => Snapshot::SOURCE_BLIZZARD_RAIDS,
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

                MemberRaidSnapshot::query()->updateOrCreate(
                    [
                        'snapshot_id' => $snapshot->id,
                        'member_id' => $member->id,
                    ],
                    [
                        'expansions' => is_array($body['expansions'] ?? null)
                            ? $body['expansions']
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

        return $this->client->raidEncountersEndpoint($slug, $charName);
    }
}
