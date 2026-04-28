<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Static lookup of WoW enchant + gem IDs to friendly names.
 *
 * The data source is two JSON files committed to the repo
 * (database/data/wow-enchants.json, database/data/wow-gems.json).
 * Each file has a `_meta` block with the date it was last reviewed
 * and a `entries` block keyed by ID. These are hand-maintained per
 * patch; the dashboard surfaces a stale-data reminder once the
 * `updated_at` falls behind or new BiS profiles introduce IDs that
 * the file doesn't cover.
 *
 * Usage: container-resolve `WowDictionary` (it's stateless beyond
 * its own JSON cache) and call `enchant($id)` / `gem($id)`. Both
 * return null when the ID isn't in the dictionary OR is present
 * but has an empty name (treated as "not yet filled in").
 */
class WowDictionary
{
    /** @var array<string, array{name:string, spell_id:?int}>|null */
    private ?array $enchants = null;
    /** @var array<string, array{name:string}>|null */
    private ?array $gems = null;
    /** @var array<string,mixed>|null */
    private ?array $enchantMeta = null;
    /** @var array<string,mixed>|null */
    private ?array $gemMeta = null;

    public function __construct(
        private readonly ?string $enchantsPath = null,
        private readonly ?string $gemsPath = null,
    ) {}

    /**
     * @return array{name:string, spell_id:?int}|null
     */
    public function enchant(int $id): ?array
    {
        $entry = $this->enchants()[(string) $id] ?? null;
        return $this->normaliseEntry($entry);
    }

    /**
     * @return array{name:string}|null
     */
    public function gem(int $id): ?array
    {
        $entry = $this->gems()[(string) $id] ?? null;
        return $this->normaliseEntry($entry);
    }

    /**
     * Stale-data signal for the dashboard. Returns the older of the
     * two `updated_at` dates (so a reminder fires as soon as either
     * file falls behind) plus a count of entries with empty names
     * across both files.
     *
     * @return array{updated_at:?CarbonImmutable, patch:?string, missing_names:int, total_ids:int}
     */
    public function freshness(): array
    {
        $this->enchants();
        $this->gems();

        $enchantUpdated = $this->parseDate($this->enchantMeta['updated_at'] ?? null);
        $gemUpdated = $this->parseDate($this->gemMeta['updated_at'] ?? null);
        $oldest = $this->oldest($enchantUpdated, $gemUpdated);

        $patches = array_values(array_filter([
            (string) ($this->enchantMeta['patch'] ?? ''),
            (string) ($this->gemMeta['patch'] ?? ''),
        ]));
        $patch = $patches === [] ? null : implode(' / ', array_unique($patches));

        $missing = 0;
        $total = 0;
        foreach (($this->enchants ?? []) as $entry) {
            $total++;
            if (! is_array($entry) || ! is_string($entry['name'] ?? null) || trim($entry['name']) === '') {
                $missing++;
            }
        }
        foreach (($this->gems ?? []) as $entry) {
            $total++;
            if (! is_array($entry) || ! is_string($entry['name'] ?? null) || trim($entry['name']) === '') {
                $missing++;
            }
        }

        return [
            'updated_at' => $oldest,
            'patch' => $patch,
            'missing_names' => $missing,
            'total_ids' => $total,
        ];
    }

    /**
     * @return array<string, array<string,mixed>>
     */
    private function enchants(): array
    {
        if ($this->enchants === null) {
            [$this->enchantMeta, $this->enchants] = $this->loadFile($this->enchantsPath ?? base_path('database/data/wow-enchants.json'));
        }
        return $this->enchants;
    }

    /**
     * @return array<string, array<string,mixed>>
     */
    private function gems(): array
    {
        if ($this->gems === null) {
            [$this->gemMeta, $this->gems] = $this->loadFile($this->gemsPath ?? base_path('database/data/wow-gems.json'));
        }
        return $this->gems;
    }

    /**
     * @return array{0: array<string,mixed>, 1: array<string, array<string,mixed>>}
     */
    private function loadFile(string $path): array
    {
        if (! is_file($path)) {
            return [[], []];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return [[], []];
        }
        $meta = is_array($decoded['_meta'] ?? null) ? $decoded['_meta'] : [];
        $entries = is_array($decoded['entries'] ?? null) ? $decoded['entries'] : [];
        return [$meta, $entries];
    }

    /**
     * Treat empty / whitespace-only names as not-filled-in so callers
     * don't have to repeat that check.
     *
     * @param  array<string,mixed>|null  $entry
     * @return array<string,mixed>|null
     */
    private function normaliseEntry(?array $entry): ?array
    {
        if (! is_array($entry)) {
            return null;
        }
        $name = $entry['name'] ?? null;
        if (! is_string($name) || trim($name) === '') {
            return null;
        }
        return $entry;
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function oldest(?CarbonImmutable $a, ?CarbonImmutable $b): ?CarbonImmutable
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }
        return $a->lessThan($b) ? $a : $b;
    }
}
