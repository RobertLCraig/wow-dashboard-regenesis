<?php

namespace App\Services\Sync;

use Illuminate\Support\Facades\Cache;

/**
 * Tiny key/value layer over Cache for the sync dashboard's "what's
 * happening right now" panel. Kept dead-simple on purpose: no DB table,
 * no events; the page reads the latest cached state and renders it.
 *
 * State shape per source:
 *   [
 *     'status'             => 'queued'|'running'|'done'|'failed',
 *     'started_at'         => ISO-8601 string,
 *     'finished_at'        => ISO-8601 string|null,
 *     'started_by_user_id' => int|null,
 *     'summary'            => array|null,   // importer return value
 *     'error'              => string|null,  // exception message on failure
 *   ]
 *
 * State TTL is 1 hour - long enough for the officer to come back, short
 * enough that an abandoned 'running' eventually disappears.
 */
class SyncStatus
{
    public const SOURCE_RAIDERIO = 'raiderio';
    public const SOURCE_GRM      = 'grm';
    public const SOURCE_WOWAUDIT = 'wowaudit';
    public const SOURCE_RAIDHELPER = 'raidhelper';
    public const SOURCE_WCL      = 'wcl';
    public const SOURCE_BLIZZARD = 'blizzard';

    public const QUEUED  = 'queued';
    public const RUNNING = 'running';
    public const DONE    = 'done';
    public const FAILED  = 'failed';

    private const TTL_SECONDS = 3600;

    public static function key(string $source): string
    {
        return "sync:state:{$source}";
    }

    public static function raiderioMutexKey(): string
    {
        return 'sync:mutex:raiderio';
    }

    public static function wowauditMutexKey(): string
    {
        return 'sync:mutex:wowaudit';
    }

    public static function wclMutexKey(): string
    {
        return 'sync:mutex:wcl';
    }

    public static function blizzardMutexKey(): string
    {
        return 'sync:mutex:blizzard';
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function get(string $source): ?array
    {
        $v = Cache::get(self::key($source));
        return is_array($v) ? $v : null;
    }

    /**
     * @param  array<string,mixed>  $state
     */
    public static function set(string $source, array $state): void
    {
        Cache::put(self::key($source), $state, self::TTL_SECONDS);
    }

    public static function clear(string $source): void
    {
        Cache::forget(self::key($source));
    }

    public static function isInProgress(?array $state): bool
    {
        return in_array($state['status'] ?? null, [self::QUEUED, self::RUNNING], true);
    }
}
