<?php

namespace App\Services\Blizzard;

use App\Models\Member;
use App\Models\MemberSocialSnapshot;
use Illuminate\Support\Collection;

/**
 * Read-side analysis over the per-character collections payloads
 * stored by SocialSnapshotImporter. Powers the farm-event planner:
 * pick a collectible (mount / pet / toy) by id and find out who in
 * the guild already has it and who does not.
 *
 * The collections columns store ID-only arrays - the importer slims
 * Blizzard's verbose payload (which embeds each entry's name + key +
 * source) down to a flat list of the only thing the analyzer actually
 * needs:
 *
 *   mounts -> { mounts: [int, int, ...] }
 *   pets   -> { pets:   [int, int, ...] }
 *   toys   -> { toys:   [int, int, ...] }
 *
 * Going slim was a Hostinger 3GB-quota response (see
 * project_social_snapshots_constraints memory). If a future feature
 * needs the names back, hydrate from a static reference rather than
 * re-fattening the per-character payloads.
 */
class CollectionsAnalyzer
{
    public const TYPE_MOUNT = 'mount';
    public const TYPE_PET = 'pet';
    public const TYPE_TOY = 'toy';

    public const TYPES = [self::TYPE_MOUNT, self::TYPE_PET, self::TYPE_TOY];

    public function isValidType(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    public function memberHas(MemberSocialSnapshot $snap, string $type, int $id): bool
    {
        return in_array($id, $this->itemsArray($snap, $type), true);
    }

    /**
     * Bucket members into has / missing for a single collectible. Each
     * entry is a thin {name, class} array so the view doesn't ferry
     * full Eloquent models when it only needs to render names.
     *
     * @param  Collection<int, Member>  $members  active roster
     * @param  Collection<int, MemberSocialSnapshot>  $snapsByMember
     * @return array{
     *   has: list<array{name:string, class:?string}>,
     *   missing: list<array{name:string, class:?string}>,
     *   no_data: list<array{name:string, class:?string}>,
     *   coverage_pct: ?int,
     * }
     */
    public function gap(Collection $members, Collection $snapsByMember, string $type, int $id): array
    {
        $has = [];
        $missing = [];
        $noData = [];

        foreach ($members as $m) {
            $entry = ['name' => $m->name, 'class' => $m->class];
            $snap = $snapsByMember->get($m->id);
            if ($snap === null || ! is_array($this->itemsArray($snap, $type))) {
                $noData[] = $entry;
                continue;
            }
            if ($this->memberHas($snap, $type, $id)) {
                $has[] = $entry;
            } else {
                $missing[] = $entry;
            }
        }

        $covered = count($has);
        $denom = $covered + count($missing);
        $coveragePct = $denom > 0 ? (int) round($covered / $denom * 100) : null;

        return [
            'has' => $has,
            'missing' => $missing,
            'no_data' => $noData,
            'coverage_pct' => $coveragePct,
        ];
    }

    /**
     * @return list<int>
     */
    private function itemsArray(MemberSocialSnapshot $snap, string $type): array
    {
        $payload = match ($type) {
            self::TYPE_MOUNT => $snap->mounts,
            self::TYPE_PET => $snap->pets,
            self::TYPE_TOY => $snap->toys,
            default => null,
        };
        if (! is_array($payload)) {
            return [];
        }
        $key = match ($type) {
            self::TYPE_MOUNT => 'mounts',
            self::TYPE_PET => 'pets',
            self::TYPE_TOY => 'toys',
        };
        $items = $payload[$key] ?? null;
        if (! is_array($items)) {
            return [];
        }
        // Filter to integers - defensive against legacy verbose payloads
        // that may still be in the DB during a gradual rollout, plus any
        // garbage Blizzard might send.
        return array_values(array_filter(array_map(
            fn ($v) => is_numeric($v) ? (int) $v : null,
            $items,
        ), fn ($v) => is_int($v)));
    }
}
