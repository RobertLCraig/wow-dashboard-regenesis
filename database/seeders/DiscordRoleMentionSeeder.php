<?php

namespace Database\Seeders;

use App\Models\DiscordRole;
use App\Models\TeamRoleMention;
use Illuminate\Database\Seeder;

/**
 * Seed the eight pingable Discord roles plus their default per-team
 * assignments. Idempotent: re-running won't duplicate rows or wipe
 * snowflakes set via the admin page.
 *
 * Roles are inserted with empty `discord_id` so a fresh deploy doesn't
 * fire pings into the wrong server. An officer fills the snowflakes
 * in via /admin/discord-roles after install.
 */
class DiscordRoleMentionSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'Mythic Raider' => 10,
            'Heroic Raider' => 20,
            'Trial Raider' => 30,
            'Social Raider' => 40,
            'M+' => 50,
            'PVP' => 60,
            'Delver' => 70,
            'member' => 80,
        ];

        $byName = [];
        $i = 0;
        foreach ($roles as $name => $sort) {
            $row = DiscordRole::query()->firstOrCreate(
                ['name' => $name],
                ['discord_id' => null, 'sort_order' => $sort],
            );
            $byName[$name] = $row->id;
            $i++;
        }

        // Default team -> roles allocations. Skips assignments already
        // in the table so re-running the seeder doesn't reset officer
        // edits made via the admin page.
        $assignments = [
            'heroic' => ['Social Raider', 'Heroic Raider'],
            'mythic' => ['Mythic Raider', 'Trial Raider'],
            'keynight' => ['M+'],
            'social' => ['member'],
        ];

        foreach ($assignments as $slug => $names) {
            foreach (array_values($names) as $position => $name) {
                if (! isset($byName[$name])) {
                    continue;
                }
                TeamRoleMention::query()->firstOrCreate(
                    ['team_slug' => $slug, 'discord_role_id' => $byName[$name]],
                    ['position' => $position],
                );
            }
        }
    }
}
