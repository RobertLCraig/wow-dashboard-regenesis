<?php

namespace App\Services\Digest;

use Illuminate\Support\Facades\DB;

/**
 * Per-table size of the current database, largest first.
 *
 * Sizes include `data_free` - the pages a DELETE frees but InnoDB keeps -
 * because that is what the host meters and what trips the plan's cap. Rows
 * alone read gigabytes lower: on 2026-08-19 the same database was 507 MB by
 * rows and 3087 MB on disk. See docs/ops-runbook.md.
 */
class DatabaseSize
{
    /**
     * Null on any driver without `information_schema` (sqlite, i.e. the test
     * suite and a fresh local checkout), so callers omit the figure rather
     * than fail on it.
     *
     * @return null|list<array{name: string, mb: float}>
     */
    public function tableSizes(): ?array
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return null;
        }

        $rows = DB::select(
            'SELECT table_name AS name, (data_length + index_length + data_free) / 1048576 AS mb
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
             ORDER BY mb DESC'
        );

        return array_map(fn ($r) => [
            'name' => (string) $r->name,
            'mb' => round((float) $r->mb, 1),
        ], $rows);
    }
}
