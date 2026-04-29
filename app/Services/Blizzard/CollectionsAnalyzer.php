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
 * The collections payloads use slightly different shapes per type:
 *
 *   mounts -> { mounts: [{ mount: { id, name, key } }, ...] }
 *   pets   -> { pets:   [{ species: { id, name, key }, ... }, ...] }
 *   toys   -> { toys:   [{ toy: { id, name, key } }, ...] }
 *
 * The analyzer normalises that so callers can think in terms of
 * "type + id".
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
        $items = $this->itemsArray($snap, $type);
        $idPath = $this->idPath($type);
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $candidate = $this->dig($item, $idPath);
            if (is_numeric($candidate) && (int) $candidate === $id) {
                return true;
            }
        }
        return false;
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
     * @return array<int, mixed>
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
        return is_array($items) ? $items : [];
    }

    /**
     * @return list<string>
     */
    private function idPath(string $type): array
    {
        return match ($type) {
            self::TYPE_MOUNT => ['mount', 'id'],
            self::TYPE_PET => ['species', 'id'],
            self::TYPE_TOY => ['toy', 'id'],
        };
    }

    /**
     * @param  array<string, mixed>  $arr
     * @param  list<string>  $path
     */
    private function dig(array $arr, array $path): mixed
    {
        $cursor = $arr;
        foreach ($path as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }
        return $cursor;
    }
}
