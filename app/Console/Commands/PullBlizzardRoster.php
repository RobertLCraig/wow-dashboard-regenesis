<?php

namespace App\Console\Commands;

use App\Services\Blizzard\BlizzardClient;
use App\Services\Blizzard\GuildRosterImporter;
use Illuminate\Console\Command;

/**
 * Pulls the authoritative guild roster from Blizzard's
 * /data/wow/guild/.../roster endpoint and reconciles against the
 * local members table.
 *
 *   php artisan blizzard:pull-roster
 *
 * Designed to run on schedule (see routes/console.php). Cheap - one
 * HTTP call covers the whole guild. Short-circuits cleanly when
 * either BLIZZARD_CLIENT_ID/SECRET or BLIZZARD_GUILD_*_SLUG are unset.
 */
class PullBlizzardRoster extends Command
{
    protected $signature = 'blizzard:pull-roster';

    protected $description = 'Pull the authoritative guild roster from Blizzard and upsert into members';

    public function handle(): int
    {
        $client = BlizzardClient::fromConfig();
        if (! $client->isConfigured()) {
            $this->info('blizzard:pull-roster skipped (BLIZZARD_CLIENT_ID / BLIZZARD_CLIENT_SECRET not set).');
            return self::SUCCESS;
        }

        $realmSlug = (string) config('blizzard.guild_realm_slug', '');
        $nameSlug = (string) config('blizzard.guild_name_slug', '');
        if ($realmSlug === '' || $nameSlug === '') {
            $this->info('blizzard:pull-roster skipped (BLIZZARD_GUILD_REALM_SLUG / BLIZZARD_GUILD_NAME_SLUG not set).');
            return self::SUCCESS;
        }

        $importer = new GuildRosterImporter(
            client: $client,
            guildKey: (string) config('grm.guild_key'),
            guildRealmSlug: $realmSlug,
            guildNameSlug: $nameSlug,
        );

        try {
            $result = $importer->pull();
        } catch (\Throwable $e) {
            $this->error('blizzard:pull-roster failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info(sprintf(
            '%d members in roster: %d new, %d updated, %d active members not seen this pull.',
            $result['total_in_roster'],
            $result['inserted'],
            $result['updated'],
            $result['not_seen_this_pull'],
        ));

        return self::SUCCESS;
    }
}
