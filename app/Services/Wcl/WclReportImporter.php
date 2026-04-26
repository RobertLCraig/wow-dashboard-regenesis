<?php

namespace App\Services\Wcl;

use App\Models\WclReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pulls the latest N WCL reports for the configured guild and upserts
 * them into wcl_reports. Idempotent on `code`, so re-running just
 * refreshes captured_at + raw_json.
 *
 * Stays at the report-list level on purpose. Per-encounter fights and
 * per-character parses are a separate, much heavier import (one query
 * per report) and live in their own importer + tables.
 */
class WclReportImporter
{
    /** Compact GraphQL query for the reports list. */
    private const REPORTS_QUERY = <<<'GQL'
    query GuildReports($name: String!, $serverSlug: String!, $serverRegion: String!, $limit: Int!) {
        reportData {
            reports(guildName: $name, guildServerSlug: $serverSlug, guildServerRegion: $serverRegion, limit: $limit) {
                data {
                    code
                    title
                    startTime
                    endTime
                    zone { id name }
                    owner { name }
                }
            }
        }
    }
    GQL;

    public function __construct(
        private readonly WclClient $client,
        private readonly string $guildKey,
        private readonly string $guildName,
        private readonly string $serverSlug,
        private readonly string $serverRegion,
        private readonly int $limit,
    ) {}

    /**
     * @return array{
     *   fetched:int, inserted:int, updated:int, last_code:?string
     * }
     */
    public function pull(): array
    {
        if (! $this->client->isConfigured()) {
            throw new \RuntimeException('WCL_CLIENT_ID / WCL_CLIENT_SECRET not configured.');
        }

        $resp = $this->client->query(self::REPORTS_QUERY, [
            'name' => $this->guildName,
            'serverSlug' => $this->serverSlug,
            'serverRegion' => $this->serverRegion,
            'limit' => $this->limit,
        ]);

        if ($resp->status() === 401) {
            // Token expired between cache write and use. Flush + retry once.
            $this->client->flushTokenCache();
            $resp = $this->client->query(self::REPORTS_QUERY, [
                'name' => $this->guildName,
                'serverSlug' => $this->serverSlug,
                'serverRegion' => $this->serverRegion,
                'limit' => $this->limit,
            ]);
        }

        if (! $resp->successful()) {
            throw new \RuntimeException(sprintf(
                'WCL GraphQL returned %d: %s',
                $resp->status(),
                mb_substr($resp->body(), 0, 200),
            ));
        }

        $body = $resp->json();
        if (isset($body['errors'])) {
            $first = $body['errors'][0]['message'] ?? 'unknown GraphQL error';
            throw new \RuntimeException("WCL GraphQL error: {$first}");
        }

        $reports = $body['data']['reportData']['reports']['data'] ?? [];
        if (! is_array($reports)) {
            throw new \RuntimeException('WCL response missing reportData.reports.data');
        }

        $now = CarbonImmutable::now();
        $inserted = 0;
        $updated = 0;
        $lastCode = null;

        DB::transaction(function () use ($reports, $now, &$inserted, &$updated, &$lastCode) {
            foreach ($reports as $row) {
                if (! is_array($row) || empty($row['code'])) {
                    continue;
                }
                $existing = WclReport::query()->where('code', $row['code'])->first();
                $payload = [
                    'guild_key' => $this->guildKey,
                    'title' => (string) ($row['title'] ?? 'Untitled'),
                    // WCL returns timestamps in milliseconds since epoch.
                    'start_time' => isset($row['startTime']) ? CarbonImmutable::createFromTimestampMs((int) $row['startTime']) : null,
                    'end_time' => isset($row['endTime']) ? CarbonImmutable::createFromTimestampMs((int) $row['endTime']) : null,
                    'zone_id' => isset($row['zone']['id']) ? (int) $row['zone']['id'] : null,
                    'zone_name' => $row['zone']['name'] ?? null,
                    'owner_name' => $row['owner']['name'] ?? null,
                    'raw_json' => $row,
                    'captured_at' => $now,
                ];

                if ($existing) {
                    $existing->forceFill($payload)->save();
                    $updated++;
                } else {
                    WclReport::query()->create(array_merge(['code' => $row['code']], $payload));
                    $inserted++;
                }
                $lastCode = $row['code'];
            }
        });

        Log::info('WCL reports imported', [
            'fetched' => count($reports), 'inserted' => $inserted, 'updated' => $updated,
        ]);

        return [
            'fetched' => count($reports),
            'inserted' => $inserted,
            'updated' => $updated,
            'last_code' => $lastCode,
        ];
    }

    public static function fromConfig(): self
    {
        return new self(
            client: WclClient::fromConfig(),
            guildKey: (string) config('grm.guild_key'),
            guildName: (string) config('wcl.guild_name'),
            serverSlug: (string) config('wcl.guild_server_slug'),
            serverRegion: (string) config('wcl.guild_server_region'),
            limit: (int) config('wcl.reports_per_pull', 25),
        );
    }
}
