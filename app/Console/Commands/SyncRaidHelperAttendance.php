<?php

namespace App\Console\Commands;

use App\Models\AttendanceStat;
use App\Services\RaidHelper\RaidHelperClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Polls Raid-Helper's /attendance endpoint and writes a fresh batch of
 * attendance_stats rows. Designed to run daily via the scheduler; safe
 * to run manually.
 *
 *   php artisan raidhelper:sync-attendance
 *   php artisan raidhelper:sync-attendance --days=90
 */
class SyncRaidHelperAttendance extends Command
{
    protected $signature = 'raidhelper:sync-attendance
        {--days=90 : Look-back window for the API call}
        {--tag= : Filter by attendance tag}
        {--channel= : Filter by channel id}';

    protected $description = 'Pull attendance stats from Raid-Helper and snapshot them locally';

    public function handle(RaidHelperClient $client): int
    {
        if (! config('raidhelper.api_key')) {
            $this->warn('RAID_HELPER_API_KEY not set; skipping.');
            return self::SUCCESS;
        }

        $days = (int) $this->option('days');
        $start = CarbonImmutable::now()->subDays($days);
        $end = CarbonImmutable::now();

        $resp = $client->attendance(
            start: $start->getTimestamp(),
            end: $end->getTimestamp(),
            tagFilter: $this->option('tag') ?: null,
            channelFilter: $this->option('channel') ?: null,
        );

        if (! $resp->successful()) {
            $this->error("Raid-Helper /attendance returned {$resp->status()}");
            return self::FAILURE;
        }

        $body = $resp->json();
        $rows = $body['result'] ?? [];
        if (! is_array($rows) || empty($rows)) {
            $this->info('No attendance rows returned.');
            return self::SUCCESS;
        }

        $now = CarbonImmutable::now();
        $guildKey = (string) config('grm.guild_key');
        $written = 0;

        DB::transaction(function () use ($rows, $start, $end, $now, $guildKey, &$written) {
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                AttendanceStat::query()->create([
                    'guild_key' => $guildKey,
                    'captured_at' => $now,
                    'tag_filter' => $this->option('tag'),
                    'channel_filter' => $this->option('channel'),
                    'time_filter_start' => $start,
                    'time_filter_end' => $end,
                    'member_name' => (string) ($row['name'] ?? $row['userId'] ?? 'unknown'),
                    'attendance_pct' => isset($row['attendance']) ? (float) $row['attendance'] : null,
                    'attended_count' => (int) ($row['attended'] ?? 0),
                    'total_count' => (int) ($row['total'] ?? 0),
                    'raw_json' => $row,
                ]);
                $written++;
            }
        });

        $this->info("Wrote $written attendance rows.");
        return self::SUCCESS;
    }
}
