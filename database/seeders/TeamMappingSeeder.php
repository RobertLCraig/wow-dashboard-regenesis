<?php

namespace Database\Seeders;

use App\Models\TeamMapping;
use App\Services\Teams\TeamResolver;
use Illuminate\Database\Seeder;

/**
 * Seeds the team_mappings table with the in-game ranks and Discord
 * roles already known to be in use on Regenesis-Silvermoon.
 *
 * Idempotent: re-running just upserts the same rows. Officers can edit
 * any of these from /admin/teams afterwards; the seeder won't clobber
 * later changes because firstOrCreate skips rows that already exist.
 */
class TeamMappingSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            // In-game ranks. Officer + Officer alt are intentionally
            // mapped to null - they don't say which raid team the
            // character is on; the Discord role does.
            [TeamMapping::SOURCE_GRM_RANK, 'Mythic Raider', null, TeamMapping::TEAM_MYTHIC, 100],
            [TeamMapping::SOURCE_GRM_RANK, 'Heroic Raider', null, TeamMapping::TEAM_HEROIC, 100],
            [TeamMapping::SOURCE_GRM_RANK, 'Heroic Try out', null, TeamMapping::TEAM_HEROIC_TRIAL, 100],
            [TeamMapping::SOURCE_GRM_RANK, 'Officer', null, null, 100],
            [TeamMapping::SOURCE_GRM_RANK, 'Officer alt', null, null, 100],

            // Discord roles. Mythic > Mythic Trial > Heroic so an officer
            // who holds multiple roles resolves to their highest team.
            [TeamMapping::SOURCE_DISCORD_ROLE, '1423356186865958923', 'Mythic Raider', TeamMapping::TEAM_MYTHIC, 300],
            [TeamMapping::SOURCE_DISCORD_ROLE, '1247628717832802409', 'Trial Raider', TeamMapping::TEAM_MYTHIC_TRIAL, 200],
            [TeamMapping::SOURCE_DISCORD_ROLE, '1247286726809096265', 'Heroic Raider', TeamMapping::TEAM_HEROIC, 100],
        ];

        foreach ($rows as [$source, $key, $label, $team, $priority]) {
            TeamMapping::query()->firstOrCreate(
                ['source' => $source, 'key' => $key],
                ['label' => $label, 'team' => $team, 'priority' => $priority]
            );
        }

        // Bust any cached lookup that predates the seed.
        app(TeamResolver::class)->flush();
    }
}
