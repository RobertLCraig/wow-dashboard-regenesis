<?php

namespace App\Services\Teams;

use App\Models\TeamSchedule;

/**
 * Single point that resolves "what's the effective schedule for team X".
 * Merges the static defaults from config('raidhelper.teams.{slug}') with
 * any officer-edited override row in team_schedules.
 *
 * Falls back gracefully: missing override row -> config defaults; missing
 * raid_time anywhere -> the global raidhelper.default_time_of_day.
 *
 * @return array{
 *   label: ?string,
 *   channel_id: ?string,
 *   template_id: ?string,
 *   template_choices: list<string>,
 *   raid_days: list<int>,
 *   raid_time: string,
 *   source: 'override'|'config',
 * }
 */
class TeamScheduleResolver
{
    /**
     * @return array<string, mixed>
     */
    public static function for(string $slug): array
    {
        $config = (array) config("raidhelper.teams.{$slug}", []);
        $override = TeamSchedule::query()->where('team_slug', $slug)->first();

        $raidDays = $override?->raid_days ?? $config['raid_days'] ?? [];
        $raidTime = $override?->raid_time ?? $config['raid_time'] ?? config('raidhelper.default_time_of_day', '19:30');

        return [
            'label' => $config['label'] ?? null,
            'channel_id' => $config['channel_id'] ?? null,
            'template_id' => $config['template_id'] ?? '9',
            // Optional whitelist for the quick-create template picker
            // (rendered only when 2+ entries exist). Empty by default,
            // which pins the panel to template_id above.
            'template_choices' => array_values(array_map('strval', (array) ($config['template_choices'] ?? []))),
            'raid_days' => array_values(array_map('intval', (array) $raidDays)),
            'raid_time' => (string) $raidTime,
            'source' => $override ? 'override' : 'config',
        ];
    }

    /**
     * Effective schedule for every team slug declared in config.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $out = [];
        foreach (array_keys((array) config('raidhelper.teams', [])) as $slug) {
            $out[$slug] = self::for($slug);
        }
        return $out;
    }
}
