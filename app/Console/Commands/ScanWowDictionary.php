<?php

namespace App\Console\Commands;

use App\Models\BisProfile;
use App\Support\WowDictionary;
use Illuminate\Console\Command;

/**
 * Audits the WoW enchant + gem name dictionary against whatever IDs the
 * current bis_profiles rows reference.
 *
 * Run after each patch / SimC pull:
 *
 *   php artisan wow:dictionary:scan
 *     -> reports any IDs referenced by BiS profiles that aren't in the
 *        dictionary, and any dictionary entries with empty names.
 *
 *   php artisan wow:dictionary:scan --add-missing
 *     -> appends stub entries for previously-unseen IDs to the JSON
 *        files (with empty names) so the manual fill-in step is just
 *        "open the file, paste names". Refreshes the _meta.updated_at.
 */
class ScanWowDictionary extends Command
{
    protected $signature = 'wow:dictionary:scan
        {--add-missing : Append stub entries for any new IDs to the JSON files}';

    protected $description = 'Audit and optionally extend the WoW enchant + gem name dictionary';

    public function handle(WowDictionary $dict): int
    {
        $referenced = $this->referencedIds();
        $enchantIds = $referenced['enchants'];
        $gemIds = $referenced['gems'];

        $this->line('');
        $this->info('BiS profiles reference ' . count($enchantIds) . ' unique enchant IDs and ' . count($gemIds) . ' unique gem IDs.');

        $this->reportBucket('Enchants', $enchantIds, fn (int $id) => $dict->enchant($id), function (int $id) use ($dict) {
            $entry = $dict->enchant($id);
            $spell = is_int($entry['spell_id'] ?? null) ? $entry['spell_id'] : null;
            return $spell
                ? "https://www.wowhead.com/spell={$spell}"
                : "https://www.wowhead.com/item-enchantment/{$id}";
        });

        $this->reportBucket('Gems', $gemIds, fn (int $id) => $dict->gem($id), fn (int $id) => "https://www.wowhead.com/item={$id}");

        if ($this->option('add-missing')) {
            $changed = false;
            $changed = $this->extendFile(
                base_path('database/data/wow-enchants.json'),
                $enchantIds,
                fn ($id) => ['name' => '', 'spell_id' => null],
            ) || $changed;
            $changed = $this->extendFile(
                base_path('database/data/wow-gems.json'),
                $gemIds,
                fn ($id) => ['name' => ''],
            ) || $changed;

            $this->line('');
            $this->info($changed ? 'Stub entries appended. Fill in names then commit.' : 'No new IDs to add.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{enchants: list<int>, gems: list<int>}
     */
    private function referencedIds(): array
    {
        $enchants = [];
        $gems = [];
        BisProfile::query()->get(['parsed_data'])->each(function (BisProfile $p) use (&$enchants, &$gems) {
            $gear = is_array($p->parsed_data['gear'] ?? null) ? $p->parsed_data['gear'] : [];
            foreach ($gear as $slot) {
                if (is_int($slot['enchant_id'] ?? null)) {
                    $enchants[$slot['enchant_id']] = true;
                }
                foreach ((array) ($slot['gem_ids'] ?? []) as $gid) {
                    if (is_int($gid)) {
                        $gems[$gid] = true;
                    }
                }
            }
        });
        $enchantList = array_keys($enchants);
        $gemList = array_keys($gems);
        sort($enchantList);
        sort($gemList);
        return ['enchants' => $enchantList, 'gems' => $gemList];
    }

    /**
     * @param  list<int>  $ids
     * @param  callable(int):?array  $lookup
     * @param  callable(int):string  $url
     */
    private function reportBucket(string $title, array $ids, callable $lookup, callable $url): void
    {
        $known = [];
        $missing = [];
        foreach ($ids as $id) {
            $entry = $lookup($id);
            if ($entry === null) {
                $missing[] = ['id' => $id, 'wowhead' => $url($id)];
            } else {
                $known[] = ['id' => $id, 'name' => $entry['name']];
            }
        }

        $this->line('');
        $this->line("<fg=cyan>{$title}</>");
        $this->line(str_repeat('-', strlen($title)));

        if ($missing === []) {
            $this->line('  All ' . count($known) . ' IDs have names.');
            return;
        }

        $this->line('  ' . count($known) . ' filled, ' . count($missing) . ' missing names:');
        foreach ($missing as $row) {
            $this->line(sprintf('    %-8d %s', $row['id'], $row['wowhead']));
        }
    }

    /**
     * @param  list<int>  $ids
     * @param  callable(int):array<string,mixed>  $stubFactory
     */
    private function extendFile(string $path, array $ids, callable $stubFactory): bool
    {
        if (! is_file($path)) {
            $this->warn("Dictionary file not found: {$path}");
            return false;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            $this->warn("Dictionary file is not valid JSON: {$path}");
            return false;
        }

        $entries = is_array($decoded['entries'] ?? null) ? $decoded['entries'] : [];
        $changed = false;
        foreach ($ids as $id) {
            $key = (string) $id;
            if (! array_key_exists($key, $entries)) {
                $entries[$key] = $stubFactory($id);
                $changed = true;
            }
        }
        if (! $changed) {
            return false;
        }

        // Numeric-ascending key order keeps diffs sane across runs.
        ksort($entries, SORT_NUMERIC);

        $decoded['entries'] = $entries;
        $decoded['_meta'] = is_array($decoded['_meta'] ?? null) ? $decoded['_meta'] : [];
        $decoded['_meta']['updated_at'] = now()->toDateString();

        file_put_contents(
            $path,
            json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
        return true;
    }
}
