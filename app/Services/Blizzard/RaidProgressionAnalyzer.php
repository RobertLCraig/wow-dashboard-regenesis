<?php

namespace App\Services\Blizzard;

use App\Models\MemberRaidSnapshot;
use Illuminate\Support\Collection;

/**
 * Read-side analysis for the per-character raid progression payloads
 * stored by RaidEncountersSnapshotImporter. Two questions officers
 * actually ask:
 *
 *   currentTier()    What is "the current raid" right now? Resolved
 *                    from the data itself (highest expansion id, then
 *                    highest instance id within it) so we don't have
 *                    to bump a config every patch.
 *
 *   hasAotcOn() / hasCeOn()   Did this character kill the final boss
 *                    on Heroic / Mythic? "Cleared every encounter"
 *                    (completed_count == total_count) is the working
 *                    proxy: simple, covers the AOTC-equivalent state
 *                    even if Blizzard ever reorders encounters[]
 *                    server-side.
 *
 * Pure read-side: no DB writes, no scraping. Works against the
 * already-stored expansions[] tree.
 */
class RaidProgressionAnalyzer
{
    public const DIFFICULTY_HEROIC = 'HEROIC';
    public const DIFFICULTY_MYTHIC = 'MYTHIC';

    /**
     * Inspect a sample of raid snapshots and pick the "current tier".
     * The latest expansion (highest id) and within it the latest
     * instance (highest id) is a robust signal that doesn't need
     * config bumps.
     *
     * @param  Collection<int, MemberRaidSnapshot>  $snapshots
     * @return array{expansion_id:int, expansion_name:string, instance_id:int, instance_name:string}|null
     */
    public function currentTier(Collection $snapshots): ?array
    {
        $bestExpansionId = null;
        $bestExpansionName = null;
        $bestInstanceId = null;
        $bestInstanceName = null;

        foreach ($snapshots as $snap) {
            $expansions = is_array($snap->expansions) ? $snap->expansions : [];
            foreach ($expansions as $ex) {
                $exId = $this->intOrNull($ex['expansion']['id'] ?? null);
                if ($exId === null) {
                    continue;
                }
                if ($bestExpansionId !== null && $exId < $bestExpansionId) {
                    continue;
                }
                $resetInstance = $bestExpansionId === null || $exId > $bestExpansionId;
                $bestExpansionId = $exId;
                $bestExpansionName = (string) ($ex['expansion']['name'] ?? '');
                if ($resetInstance) {
                    $bestInstanceId = null;
                    $bestInstanceName = null;
                }

                foreach (($ex['instances'] ?? []) as $inst) {
                    $instId = $this->intOrNull($inst['instance']['id'] ?? null);
                    if ($instId === null) {
                        continue;
                    }
                    if ($bestInstanceId === null || $instId > $bestInstanceId) {
                        $bestInstanceId = $instId;
                        $bestInstanceName = (string) ($inst['instance']['name'] ?? '');
                    }
                }
            }
        }

        if ($bestExpansionId === null || $bestInstanceId === null) {
            return null;
        }

        return [
            'expansion_id' => $bestExpansionId,
            'expansion_name' => $bestExpansionName ?? '',
            'instance_id' => $bestInstanceId,
            'instance_name' => $bestInstanceName ?? '',
        ];
    }

    public function hasAotcOn(MemberRaidSnapshot $snap, int $instanceId): bool
    {
        return $this->hasFullClearOn($snap, $instanceId, self::DIFFICULTY_HEROIC);
    }

    public function hasCeOn(MemberRaidSnapshot $snap, int $instanceId): bool
    {
        return $this->hasFullClearOn($snap, $instanceId, self::DIFFICULTY_MYTHIC);
    }

    /**
     * "All encounters cleared" check for a specific instance and
     * difficulty. Returns false on missing data rather than throwing
     * so callers can use it inline.
     */
    private function hasFullClearOn(MemberRaidSnapshot $snap, int $instanceId, string $difficulty): bool
    {
        $expansions = is_array($snap->expansions) ? $snap->expansions : [];
        foreach ($expansions as $ex) {
            foreach (($ex['instances'] ?? []) as $inst) {
                if (($inst['instance']['id'] ?? null) !== $instanceId) {
                    continue;
                }
                foreach (($inst['modes'] ?? []) as $mode) {
                    if (($mode['difficulty']['type'] ?? null) !== $difficulty) {
                        continue;
                    }
                    $progress = $mode['progress'] ?? null;
                    if (! is_array($progress)) {
                        return false;
                    }
                    $completed = $this->intOrNull($progress['completed_count'] ?? null) ?? 0;
                    $total = $this->intOrNull($progress['total_count'] ?? null) ?? 0;
                    return $total > 0 && $completed >= $total;
                }
                return false;
            }
        }
        return false;
    }

    private function intOrNull(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }
}
