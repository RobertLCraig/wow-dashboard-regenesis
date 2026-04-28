<?php

namespace App\Services\Blizzard;

use App\Models\Member;
use App\Models\MemberMplusSnapshot;
use App\Models\Snapshot;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pull /profile/wow/character/.../mythic-keystone-profile for every
 * active member and store the canonical M+ data alongside Raider.IO's
 * computed view.
 *
 * Same fan-out shape as the other Blizzard importers (Http::pool +
 * batched concurrency). 404 is the normal "never run a key" outcome
 * for low-key alts and fresh trials, treated as missing not error.
 *
 * RIO stays the day-to-day display source for now (the user uses both
 * its score breakdowns and per-dungeon detail). This pulls Blizzard's
 * own rating + runs so we can cross-check, fall back if RIO lapses,
 * and render Blizzard's own colour band where it matters.
 */
class MplusSnapshotImporter
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
                    Log::warning('blizzard mplus fetch failed', [
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
                    // No runs ever, or character never logged in. Not
                    // an error - just nothing to record.
                    $missing++;
                    continue;
                }

                if (! $resp->successful()) {
                    Log::warning('blizzard mplus non-2xx', [
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
                    'source' => Snapshot::SOURCE_BLIZZARD_MPLUS,
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

                MemberMplusSnapshot::query()->updateOrCreate(
                    [
                        'snapshot_id' => $snapshot->id,
                        'member_id' => $member->id,
                    ],
                    [
                        'mythic_rating' => $this->extractRating($body),
                        'current_period_runs' => $this->extractCurrentPeriodRuns($body),
                        'seasons' => $this->extractSeasons($body),
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

        return $this->client->mythicKeystoneProfileEndpoint($slug, $charName);
    }

    /**
     * @param  array<string,mixed>  $body
     */
    private function extractRating(array $body): ?float
    {
        $raw = $body['current_mythic_rating']['rating'] ?? null;
        if (! is_numeric($raw)) {
            return null;
        }
        return round((float) $raw, 1);
    }

    /**
     * @param  array<string,mixed>  $body
     * @return array<int,array<string,mixed>>|null
     */
    private function extractCurrentPeriodRuns(array $body): ?array
    {
        $runs = $body['current_period']['best_runs'] ?? null;
        return is_array($runs) ? $runs : null;
    }

    /**
     * @param  array<string,mixed>  $body
     * @return array<int,array<string,mixed>>|null
     */
    private function extractSeasons(array $body): ?array
    {
        $seasons = $body['seasons'] ?? null;
        return is_array($seasons) ? $seasons : null;
    }
}
