<?php

namespace App\Console\Commands;

use App\Models\BisProfile;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Seed bis_profiles for healing specs (plus Augmentation Evoker).
 *
 * SimulationCraft's profiles/MID1 directory only ships profiles for
 * DPS and tank specs - healers are intentionally out of scope on
 * their end, so simc:pull will never populate them. Without these
 * stub rows the BiS comparison widget on the character page returns
 * null for any healer (no candidate profiles), and the placeholder
 * fires.
 *
 * The data file at database/data/healer-bis-profiles.json carries:
 *   - consumables harvested from the same-class DPS profile (the
 *     flask / food / potion / aug rune / weapon oil are universal
 *     per class+role);
 *   - empty gear (per-slot item ids and enchants need a curated
 *     source like Wowhead / Method / QuestionablyEpic; SimC won't
 *     supply them).
 *
 * Even with empty gear the widget renders: the table shows "Have:
 * <player's item>" rows with no BiS column, and the consumables
 * footer displays the recommended flask/food/etc. As BiS data gets
 * filled in slot by slot in the JSON, the comparison sharpens.
 *
 *   php artisan bis:seed-healers           # upsert from JSON
 *   php artisan bis:seed-healers --force   # also wipe non-stub profiles for these specs
 *
 * Idempotent: re-runs replace each (class, spec, hero_talent) row
 * with the JSON contents.
 */
class SeedHealerBisProfiles extends Command
{
    protected $signature = 'bis:seed-healers
        {--path= : Override the JSON data file path}';

    protected $description = 'Upsert bis_profiles rows for healing specs (and Augmentation Evoker) from the curated JSON data file';

    public function handle(): int
    {
        $path = (string) ($this->option('path') ?? database_path('data/healer-bis-profiles.json'));
        if (! is_file($path)) {
            $this->error("Healer BiS data file not found: {$path}");
            return self::FAILURE;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            $this->error("Could not read {$path}");
            return self::FAILURE;
        }

        try {
            $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->error("Invalid JSON in {$path}: " . $e->getMessage());
            return self::FAILURE;
        }

        $profiles = $decoded['profiles'] ?? null;
        if (! is_array($profiles)) {
            $this->error("'profiles' key missing or not an array in {$path}");
            return self::FAILURE;
        }

        $now = CarbonImmutable::now();
        $upserted = 0;
        $skipped = 0;

        foreach ($profiles as $i => $row) {
            if (! is_array($row)) {
                $this->warn("entry #{$i} is not an object - skipping");
                $skipped++;
                continue;
            }
            $class = $row['class'] ?? null;
            $spec = $row['spec'] ?? null;
            if (! is_string($class) || ! is_string($spec)) {
                $this->warn("entry #{$i} missing class/spec - skipping");
                $skipped++;
                continue;
            }

            $heroTalent = $row['hero_talent'] ?? null;
            $profileName = is_string($row['profile_name'] ?? null)
                ? $row['profile_name']
                : sprintf('MID1_%s_%s_stub', $class, $spec);

            $gear = is_array($row['gear'] ?? null) ? $row['gear'] : [];
            $consumables = is_array($row['consumables'] ?? null) ? $row['consumables'] : [];

            BisProfile::query()->updateOrCreate(
                [
                    'class' => $class,
                    'spec' => $spec,
                    'hero_talent' => $heroTalent,
                ],
                [
                    'profile_name' => $profileName,
                    'source_path' => $path,
                    'parsed_data' => [
                        'class' => $class,
                        'spec' => $spec,
                        'hero_talent' => $heroTalent,
                        'profile_name' => $profileName,
                        'gear' => $gear,
                        'consumables' => $consumables,
                        'gear_ilvl' => null,
                    ],
                    'captured_at' => $now,
                ]
            );
            $upserted++;
        }

        $this->info(sprintf(
            '%d healer BiS profiles upserted from %s (%d skipped).',
            $upserted,
            $path,
            $skipped,
        ));

        return self::SUCCESS;
    }
}
