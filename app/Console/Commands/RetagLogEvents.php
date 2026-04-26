<?php

namespace App\Console\Commands;

use App\Models\LogEvent;
use App\Services\Grm\GrmNormalizer;
use Illuminate\Console\Command;

/**
 * Retag existing log_events rows after a logTypeName() correction.
 *
 * Earlier versions of GrmNormalizer used a guessed code -> name table
 * (kicks tagged EVENT_BIRTHDAY, level-ups tagged LEFT, etc.). The map
 * has since been verified against upstream GRM_Log.lua. This command
 * recomputes type_name for every existing row from the stored
 * type_code + raw_json, so production rows ingested under the old map
 * pick up the correct labels without re-ingesting snapshots.
 *
 *   php artisan grm:retag-logs
 *   php artisan grm:retag-logs --dry-run
 */
class RetagLogEvents extends Command
{
    protected $signature = 'grm:retag-logs {--dry-run : Show changes without writing}';

    protected $description = 'Recompute type_name on log_events rows from the current code map';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $changed = 0;
        $unchanged = 0;
        $byTransition = [];

        LogEvent::query()->orderBy('id')->chunkById(500, function ($chunk) use ($dryRun, &$changed, &$unchanged, &$byTransition) {
            foreach ($chunk as $log) {
                $values = is_array($log->raw_json) ? array_values($log->raw_json) : [];
                $newName = GrmNormalizer::logTypeName(
                    (int) $log->type_code,
                    $values,
                    (string) $log->message_raw,
                );

                if ($newName === $log->type_name) {
                    $unchanged++;
                    continue;
                }

                $key = sprintf('%s -> %s (code %d)', $log->type_name ?? 'NULL', $newName ?? 'NULL', $log->type_code);
                $byTransition[$key] = ($byTransition[$key] ?? 0) + 1;
                $changed++;

                if (! $dryRun) {
                    $log->forceFill(['type_name' => $newName])->save();
                }
            }
        });

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Updated $changed rows, $unchanged unchanged.");
        if ($byTransition !== []) {
            $this->newLine();
            $this->line('Transitions:');
            ksort($byTransition);
            foreach ($byTransition as $key => $count) {
                $this->line(sprintf('  %6d  %s', $count, $key));
            }
        }

        return self::SUCCESS;
    }
}
