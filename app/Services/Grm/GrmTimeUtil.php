<?php

namespace App\Services\Grm;

use Carbon\CarbonImmutable;

/**
 * GRM stores time data across a few shapes:
 *
 *   - lastOnline (int): hours since the player was last seen. THE
 *     authoritative "when were they last online" field. 0 if currently
 *     online.
 *   - lastOnlineTime ({y, m, d, h}): a DURATION since last online,
 *     decomposed for display. Despite the field name, NOT an absolute
 *     timestamp - empirically a player with lastOnline=5954h has
 *     lastOnlineTime=[0,8,5,2] = 0y 8m 5d 2h = ~248 days = 5952 hours.
 *     We ignore this in favour of the lastOnline integer.
 *   - rankHist / joinDateHist rows: positional with a "yyyymmdd" string
 *     and a unix epoch as later elements.
 *
 * Time-of-day fields are recorded in the user's local timezone client-
 * side; we treat them as Europe/London since that's the guild's tz.
 *
 * Pure helpers, no Eloquent or facade usage so this class is trivially
 * unit-testable.
 */
class GrmTimeUtil
{
    /**
     * Compute the absolute timestamp of a member's last login by
     * subtracting GRM's lastOnline (hours) from the snapshot's
     * captured_at. Stable across snapshots so the differ doesn't
     * misidentify a player as "newly inactive" every ingest.
     *
     * @param  array<int|string,mixed>  $row  Member row from GRM
     */
    public static function lastOnlineAt(array $row, CarbonImmutable $capturedAt): ?CarbonImmutable
    {
        if (! empty($row['isOnline'])) {
            return $capturedAt;
        }
        $hours = $row['lastOnline'] ?? null;
        if (is_int($hours) && $hours >= 0) {
            return $capturedAt->subHours($hours);
        }
        return null;
    }

    /**
     * GRM log rows end with a [day, month, year, hour, minute] positional
     * array. Convert that to a CarbonImmutable.
     *
     * @param  array<int|string,mixed>|null  $arr
     */
    public static function logTimestamp(?array $arr, string $tz = 'Europe/London'): ?CarbonImmutable
    {
        if (! $arr) {
            return null;
        }
        $values = array_values($arr);
        if (count($values) < 5) {
            return null;
        }
        [$day, $month, $year, $hour, $minute] = $values;
        if (! $year || ! $month || ! $day) {
            return null;
        }

        return CarbonImmutable::create(
            (int) $year,
            (int) $month,
            (int) $day,
            (int) $hour,
            (int) $minute,
            0,
            $tz,
        );
    }

    /**
     * Pull a join date out of GRM's joinDateHist - the first row's 5th
     * element is a "yyyymmdd" string that's the most reliable form, and
     * the 6th element is a unix epoch when known.
     *
     * @param  array<int|string,mixed>|null  $hist
     */
    public static function joinDate(?array $hist): ?CarbonImmutable
    {
        if (! $hist) {
            return null;
        }
        $first = $hist[1] ?? array_values($hist)[0] ?? null;
        if (! is_array($first)) {
            return null;
        }
        $values = array_values($first);
        // [rankName, day, month, year, "yyyymmdd", unix_ts, ...]
        $unix = $values[5] ?? null;
        if (is_int($unix) && $unix > 0) {
            return CarbonImmutable::createFromTimestampUTC($unix);
        }
        $yyyymmdd = $values[4] ?? null;
        if (is_string($yyyymmdd) && strlen($yyyymmdd) === 8 && ctype_digit($yyyymmdd)) {
            return CarbonImmutable::createFromFormat(
                'Ymd',
                $yyyymmdd,
                'Europe/London',
            )->startOfDay() ?: null;
        }
        return null;
    }
}
