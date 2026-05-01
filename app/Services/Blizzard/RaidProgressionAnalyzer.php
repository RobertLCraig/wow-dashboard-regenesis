<?php

namespace App\Services\Blizzard;

use App\Models\MemberRaidSnapshot;
use Illuminate\Support\Collection;

/**
 * Read-side analysis for the per-character raid progression payloads
 * stored by RaidEncountersSnapshotImporter. Three questions officers
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
 *   teamBossBreakdown()   Per-instance, per-difficulty boss-by-boss
 *                    rollup for a team. A boss is "team-killed" if at
 *                    least one team member's snapshot has it cleared,
 *                    matching the existing "best player on the team
 *                    represents the team" semantics from the rollup
 *                    counts. Difficulty is capped so a Heroic team
 *                    never sees Mythic in its breakdown.
 *
 * Pure read-side: no DB writes, no scraping. Works against the
 * already-stored expansions[] tree.
 */
class RaidProgressionAnalyzer
{
    public const DIFFICULTY_NORMAL = 'NORMAL';
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

    /**
     * Per-instance, per-difficulty boss-level breakdown rolled up across
     * a team's member raid snapshots. A boss is counted as "team-killed"
     * if any member in the input has it cleared on that difficulty;
     * that matches how the team's headline progression count works
     * already (best player on the team represents the team).
     *
     * Difficulty is capped:
     *   - 'mythic' team -> shows MYTHIC and HEROIC
     *   - 'heroic' team -> shows HEROIC and NORMAL
     *   - 'normal' team -> shows NORMAL only
     *
     * Instances are returned newest first (highest expansion id, then
     * highest instance id). Difficulties within an instance are
     * returned in descending difficulty order. Difficulties with no
     * encounters and instances with no surviving difficulties are
     * dropped so the view doesn't render empty rows.
     *
     * Pass $onlyExpansionId to restrict the breakdown to a single
     * expansion (typically the current tier as picked by currentTier()).
     * Instances from older expansions are dropped silently. When null
     * every expansion present in the snapshots is included.
     *
     * @param  Collection<int, MemberRaidSnapshot>  $snaps
     * @param  'mythic'|'heroic'|'normal'  $maxDifficulty
     * @return list<array{
     *   id:int, name:string,
     *   expansion_id:int, expansion_name:string,
     *   difficulties: list<array{
     *     type:string, label:string, short:string,
     *     killed:int, total:int,
     *     encounters: list<array{id:int, name:string, killers:int, last_kill_ms:?int}>,
     *   }>,
     * }>
     */
    public function teamBossBreakdown(Collection $snaps, string $maxDifficulty = 'mythic', ?int $onlyExpansionId = null): array
    {
        $allowed = $this->allowedDifficulties($maxDifficulty);
        if ($allowed === [] || $snaps->isEmpty()) {
            return [];
        }
        // Lower index = higher difficulty in $allowed; used for sorting later.
        $diffOrder = array_flip($allowed);

        $instances = [];

        foreach ($snaps as $snap) {
            foreach ((array) ($snap->expansions ?? []) as $ex) {
                $expansionId = $this->intOrNull($ex['expansion']['id'] ?? null);
                if ($expansionId === null) {
                    continue;
                }
                if ($onlyExpansionId !== null && $expansionId !== $onlyExpansionId) {
                    continue;
                }
                $expansionName = is_string($ex['expansion']['name'] ?? null) ? $ex['expansion']['name'] : '';

                foreach (($ex['instances'] ?? []) as $inst) {
                    $instId = $this->intOrNull($inst['instance']['id'] ?? null);
                    if ($instId === null) {
                        continue;
                    }
                    $instName = is_string($inst['instance']['name'] ?? null) ? $inst['instance']['name'] : '';

                    if (! isset($instances[$instId])) {
                        $instances[$instId] = [
                            'id' => $instId,
                            'name' => $instName,
                            'expansion_id' => $expansionId,
                            'expansion_name' => $expansionName,
                            'difficulties' => [],
                        ];
                    } elseif ($instName !== '' && $instances[$instId]['name'] === '') {
                        $instances[$instId]['name'] = $instName;
                    }

                    foreach (($inst['modes'] ?? []) as $mode) {
                        $type = is_string($mode['difficulty']['type'] ?? null) ? $mode['difficulty']['type'] : '';
                        if (! isset($diffOrder[$type])) {
                            continue;
                        }
                        $progress = $mode['progress'] ?? null;
                        if (! is_array($progress)) {
                            continue;
                        }

                        $modeLabel = is_string($mode['difficulty']['name'] ?? null)
                            ? $mode['difficulty']['name']
                            : ucfirst(strtolower($type));
                        $totalCount = $this->intOrNull($progress['total_count'] ?? null) ?? 0;

                        if (! isset($instances[$instId]['difficulties'][$type])) {
                            $instances[$instId]['difficulties'][$type] = [
                                'type' => $type,
                                'label' => $modeLabel,
                                'short' => $this->difficultyShort($type),
                                'total' => $totalCount,
                                'encounters' => [],
                            ];
                        } elseif ($totalCount > $instances[$instId]['difficulties'][$type]['total']) {
                            $instances[$instId]['difficulties'][$type]['total'] = $totalCount;
                        }

                        foreach (($progress['encounters'] ?? []) as $enc) {
                            $encId = $this->intOrNull($enc['encounter']['id'] ?? null);
                            if ($encId === null) {
                                continue;
                            }
                            $encName = is_string($enc['encounter']['name'] ?? null) ? $enc['encounter']['name'] : '';
                            $cc = $this->intOrNull($enc['completed_count'] ?? null) ?? 0;
                            $lk = $this->intOrNull($enc['last_kill_timestamp'] ?? null);

                            $bucket = &$instances[$instId]['difficulties'][$type]['encounters'][$encId];
                            if ($bucket === null) {
                                $bucket = [
                                    'id' => $encId,
                                    'name' => $encName,
                                    'killers' => 0,
                                    'last_kill_ms' => null,
                                ];
                            } elseif ($encName !== '' && $bucket['name'] === '') {
                                $bucket['name'] = $encName;
                            }
                            if ($cc > 0) {
                                $bucket['killers']++;
                                if ($lk !== null && ($bucket['last_kill_ms'] === null || $lk > $bucket['last_kill_ms'])) {
                                    $bucket['last_kill_ms'] = $lk;
                                }
                            }
                            unset($bucket);
                        }
                    }
                }
            }
        }

        $out = [];
        foreach ($instances as $inst) {
            $diffs = [];
            foreach ($inst['difficulties'] as $diff) {
                $encounters = array_values($diff['encounters']);
                if ($encounters === []) {
                    continue;
                }
                $killed = 0;
                foreach ($encounters as $e) {
                    if ($e['killers'] > 0) {
                        $killed++;
                    }
                }
                $diffs[] = [
                    'type' => $diff['type'],
                    'label' => $diff['label'],
                    'short' => $diff['short'],
                    'killed' => $killed,
                    'total' => $diff['total'] > 0 ? $diff['total'] : count($encounters),
                    'encounters' => $encounters,
                ];
            }
            if ($diffs === []) {
                continue;
            }
            usort($diffs, fn ($a, $b) => $diffOrder[$a['type']] <=> $diffOrder[$b['type']]);
            $out[] = [
                'id' => $inst['id'],
                'name' => $inst['name'],
                'expansion_id' => $inst['expansion_id'],
                'expansion_name' => $inst['expansion_name'],
                'difficulties' => $diffs,
            ];
        }

        // Newest expansion first, then newest instance within the
        // expansion. Matches what officers think of as "current tier
        // first, older tiers below".
        usort($out, fn ($a, $b) => [$b['expansion_id'], $b['id']] <=> [$a['expansion_id'], $a['id']]);

        return $out;
    }

    /**
     * @return list<string>
     */
    private function allowedDifficulties(string $maxDifficulty): array
    {
        return match ($maxDifficulty) {
            'mythic' => [self::DIFFICULTY_MYTHIC, self::DIFFICULTY_HEROIC],
            'heroic' => [self::DIFFICULTY_HEROIC, self::DIFFICULTY_NORMAL],
            'normal' => [self::DIFFICULTY_NORMAL],
            default  => [self::DIFFICULTY_MYTHIC, self::DIFFICULTY_HEROIC],
        };
    }

    private function difficultyShort(string $type): string
    {
        return match ($type) {
            self::DIFFICULTY_MYTHIC => 'M',
            self::DIFFICULTY_HEROIC => 'H',
            self::DIFFICULTY_NORMAL => 'N',
            default => substr($type, 0, 1) ?: '?',
        };
    }

    private function intOrNull(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }
}
