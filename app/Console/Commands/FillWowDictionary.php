<?php

namespace App\Console\Commands;

use App\Services\Blizzard\BlizzardClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Auto-populates the WoW enchant + gem name dictionary.
 *
 * Sources:
 *   - Gems via Blizzard's Game Data API (/data/wow/item/{id}, static
 *     namespace). Requires BLIZZARD_CLIENT_ID + BLIZZARD_CLIENT_SECRET.
 *   - Enchants via wago.tools' SpellItemEnchantment DB2 export. The
 *     Blizzard API does not expose item-enchantment lookups; wago.tools
 *     is the same data Blizzard's client reads, hosted as CSV. No auth
 *     needed but each query takes ~1-2s.
 *
 * Usage:
 *   php artisan wow:dictionary:fill            # add names to known IDs
 *   php artisan wow:dictionary:fill --rescan   # add new bis_profile IDs first
 *   php artisan wow:dictionary:fill --force    # overwrite existing names
 *   php artisan wow:dictionary:fill --build=12.0.5.67235  # pin a build
 *
 * Re-run each patch alongside `simc:pull`. Safe to re-run; skips
 * already-named entries unless --force is set.
 */
class FillWowDictionary extends Command
{
    protected $signature = 'wow:dictionary:fill
        {--rescan : First, append stub entries for any new bis_profile IDs}
        {--force : Overwrite names that are already filled in}
        {--build= : wago.tools build to query (latest live by default)}';

    protected $description = 'Populate enchant + gem names from Blizzard + wago.tools';

    private const WAGO_BASE = 'https://wago.tools/db2/SpellItemEnchantment/csv';

    public function handle(BlizzardClient $blizzard): int
    {
        if ($this->option('rescan')) {
            $this->call('wow:dictionary:scan', ['--add-missing' => true]);
        }

        $build = $this->resolveBuild($this->option('build'));
        $this->info("Using wago.tools build {$build} for enchants.");

        $force = (bool) $this->option('force');

        $enchantsTouched = $this->fillFile(
            base_path('database/data/wow-enchants.json'),
            'enchant',
            fn (int $id) => $this->fetchEnchant($build, $id),
            $force,
        );

        $gemsTouched = 0;
        if ($blizzard->isConfigured()) {
            $gemsTouched = $this->fillFile(
                base_path('database/data/wow-gems.json'),
                'gem',
                fn (int $id) => $this->fetchItem($blizzard, $id),
                $force,
            );
        } else {
            $this->warn('Blizzard credentials not set; skipping gems.');
        }

        $this->line('');
        $this->info(sprintf('Filled %d enchant + %d gem entries.', $enchantsTouched, $gemsTouched));
        return self::SUCCESS;
    }

    /**
     * @param  callable(int):?array<string,mixed>  $fetcher
     */
    private function fillFile(string $path, string $kind, callable $fetcher, bool $force): int
    {
        if (! is_file($path)) {
            $this->warn("Dictionary file not found: {$path}");
            return 0;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            $this->warn("Dictionary file is not valid JSON: {$path}");
            return 0;
        }

        $entries = is_array($decoded['entries'] ?? null) ? $decoded['entries'] : [];
        $touched = 0;

        foreach ($entries as $idStr => $entry) {
            if (! is_numeric($idStr)) {
                continue;
            }
            $hasName = is_array($entry) && is_string($entry['name'] ?? null) && trim($entry['name']) !== '';
            if ($hasName && ! $force) {
                continue;
            }

            $id = (int) $idStr;
            $payload = $fetcher($id);
            if ($payload === null) {
                $this->warn("  {$kind} #{$id}: not found");
                continue;
            }

            $name = $payload['name'] ?? null;
            if (! is_string($name) || $name === '') {
                $this->warn("  {$kind} #{$id}: source returned no name");
                continue;
            }

            $entries[$idStr] = is_array($entries[$idStr]) ? $entries[$idStr] : [];
            $entries[$idStr]['name'] = $name;
            if ($kind === 'enchant') {
                $spellId = $payload['spell_id'] ?? null;
                $entries[$idStr]['spell_id'] = is_int($spellId) ? $spellId : null;
            }
            $this->line(sprintf('  %s #%d -> %s', $kind, $id, $name));
            $touched++;
        }

        if ($touched === 0) {
            return 0;
        }

        ksort($entries, SORT_NUMERIC);
        $decoded['entries'] = $entries;
        $decoded['_meta'] = is_array($decoded['_meta'] ?? null) ? $decoded['_meta'] : [];
        $decoded['_meta']['updated_at'] = now()->toDateString();

        file_put_contents(
            $path,
            json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );

        return $touched;
    }

    /**
     * @return array{name:string, spell_id:?int}|null
     */
    private function fetchEnchant(string $build, int $id): ?array
    {
        $response = Http::timeout(15)->get(self::WAGO_BASE, [
            'build' => $build,
            'filter[ID]' => $id,
        ]);
        if (! $response->successful()) {
            return null;
        }
        $row = $this->firstCsvRow($response->body());
        if ($row === null || ! isset($row['Name_lang'])) {
            return null;
        }
        $name = $this->cleanEnchantName($row['Name_lang']);
        if ($name === '') {
            return null;
        }
        // wago's CSV doesn't carry the trigger spell id directly for
        // most enchants; leaving spell_id null falls back to the
        // wowhead.com/item-enchantment URL which works fine.
        return ['name' => $name, 'spell_id' => null];
    }

    /**
     * @return array{name:string}|null
     */
    private function fetchItem(BlizzardClient $blizzard, int $id): ?array
    {
        $response = $blizzard->item($id);
        if (! $response->successful()) {
            return null;
        }
        $name = $this->localisedName($response->json('name'));
        if ($name === null) {
            return null;
        }
        return ['name' => $name];
    }

    /**
     * Resolve a Battle.net localised name field, which is either a
     * string (older endpoints) or an object keyed by locale (newer).
     * Prefer the configured locale, fall back to en_GB / en_US.
     */
    private function localisedName(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value !== '' ? $value : null;
        }
        if (! is_array($value)) {
            return null;
        }
        $preferred = (string) config('blizzard.locale', 'en_GB');
        foreach ([$preferred, 'en_GB', 'en_US'] as $locale) {
            if (isset($value[$locale]) && is_string($value[$locale]) && $value[$locale] !== '') {
                return $value[$locale];
            }
        }
        foreach ($value as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Strip the inline icon marker ("|A:Professions-ChatIcon-...|a")
     * Blizzard glues onto every Tier 2/3 crafted enchant name. Trim
     * whitespace and surrounding quotes the CSV export sometimes
     * keeps.
     */
    private function cleanEnchantName(string $raw): string
    {
        $name = trim($raw, " \t\n\r\0\x0B\"");
        return trim((string) preg_replace('/\s*\|A:[^|]*\|a\s*$/u', '', $name));
    }

    /**
     * Parse the first non-header row of a CSV string into a column
     * map. wago's exports are well-behaved (RFC 4180-ish) so a single
     * `str_getcsv()` per line works.
     *
     * @return array<string,string>|null
     */
    private function firstCsvRow(string $csv): ?array
    {
        $lines = preg_split('/\R/', trim($csv));
        if ($lines === false || count($lines) < 2) {
            return null;
        }
        $headers = str_getcsv($lines[0]);
        $values = str_getcsv($lines[1]);
        if ($headers === [] || count($headers) !== count($values)) {
            return null;
        }
        return array_combine($headers, $values) ?: null;
    }

    /**
     * Resolve which wago.tools build to query. Explicit option wins;
     * otherwise hit /api/builds and pick the latest "wow" (live)
     * entry. wago.tools serves data per-build and rejects queries
     * against unknown builds, so we can't just hardcode a default.
     */
    private function resolveBuild(?string $explicit): string
    {
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }
        $response = Http::timeout(15)->get('https://wago.tools/api/builds');
        if (! $response->successful()) {
            throw new \RuntimeException('wago.tools /api/builds returned ' . $response->status());
        }
        $payload = $response->json();
        $candidates = is_array($payload['wow'] ?? null) ? $payload['wow'] : [];
        foreach ($candidates as $entry) {
            $version = $entry['version'] ?? null;
            if (is_string($version) && $version !== '') {
                return $version;
            }
        }
        throw new \RuntimeException('wago.tools /api/builds had no wow entries');
    }
}
