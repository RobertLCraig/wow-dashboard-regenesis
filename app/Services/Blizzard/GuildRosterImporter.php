<?php

namespace App\Services\Blizzard;

use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pull the guild's authoritative member list from
 * /data/wow/guild/{realm}/{name}/roster and reconcile against the
 * local `members` table.
 *
 * Hybrid model: Blizzard owns "is this character in the guild right
 * now". GRM still owns custom_note, alt linkage, join_date, the
 * recommend_* officer flags, and the leaver/banned ledger - none of
 * which the Blizzard endpoint exposes. So this importer is deliberately
 * narrow: it upserts identity columns (name, realm_slug,
 * blizzard_character_id), the few authoritative facts Blizzard does
 * carry (level, faction, rank index), and `last_blizzard_seen_at` so
 * downstream code can tell the two sources apart.
 *
 * It does NOT mark missing-from-roster members as "left". GRM tracks
 * leavers with richer context (PlayersThatLeftHistory + ban metadata)
 * and the dashboard surfaces "Blizzard hasn't seen this member since X"
 * via stale `last_blizzard_seen_at` instead of a state flip.
 *
 * Match strategy: prefer the stable blizzard_character_id once known,
 * otherwise fall back to (guild_key, name). The GRM convention
 * collapses realm spaces - "Char-TwistingNether" rather than
 * "Char-Twisting Nether" - so we mirror that when constructing the
 * name from a Blizzard roster entry.
 */
class GuildRosterImporter
{
    public function __construct(
        private readonly BlizzardClient $client,
        private readonly string $guildKey,
        private readonly string $guildRealmSlug,
        private readonly string $guildNameSlug,
    ) {}

    /**
     * @return array{
     *   inserted:int,
     *   updated:int,
     *   total_in_roster:int,
     *   not_seen_this_pull:int,
     *   pulled_at:string,
     * }
     */
    public function pull(): array
    {
        if (! $this->client->isConfigured()) {
            throw new \RuntimeException(
                'Blizzard client credentials are not configured. '
                . 'Set BLIZZARD_CLIENT_ID and BLIZZARD_CLIENT_SECRET.'
            );
        }
        if ($this->guildRealmSlug === '' || $this->guildNameSlug === '') {
            throw new \RuntimeException(
                'Blizzard guild identity is not configured. '
                . 'Set BLIZZARD_GUILD_REALM_SLUG and BLIZZARD_GUILD_NAME_SLUG.'
            );
        }

        $response = $this->client->guildRoster($this->guildRealmSlug, $this->guildNameSlug);
        if (! $response->successful()) {
            throw new \RuntimeException(sprintf(
                'Blizzard guild roster fetch failed: %d %s',
                $response->status(),
                mb_substr((string) $response->body(), 0, 200),
            ));
        }

        $body = $response->json();
        $rows = is_array($body) && isset($body['members']) && is_array($body['members'])
            ? $body['members']
            : [];

        $now = CarbonImmutable::now();
        $inserted = 0;
        $updated = 0;
        $seenIds = [];

        DB::transaction(function () use ($rows, $now, &$inserted, &$updated, &$seenIds) {
            foreach ($rows as $row) {
                $entry = $this->extract($row);
                if ($entry === null) {
                    continue;
                }

                $member = $this->resolveMember($entry['character_id'], $entry['name']);

                // Roster presence implies the character record is
                // currently valid (the /status endpoint would return
                // is_valid:true). We don't flip stale rows to false
                // here - that would need an explicit /status call per
                // missing member, which a future sweep can do.
                $values = [
                    'guild_key' => $this->guildKey,
                    'name' => $entry['name'],
                    'blizzard_character_id' => $entry['character_id'],
                    'realm_slug' => $entry['realm_slug'],
                    'level' => $entry['level'],
                    'rank_index' => $entry['rank_index'],
                    'faction' => $entry['faction'],
                    'last_blizzard_seen_at' => $now,
                    'last_seen_at' => $now,
                    'is_valid_at_blizzard' => true,
                    'status' => Member::STATUS_ACTIVE,
                ];

                if ($member === null) {
                    $values['first_seen_at'] = $now;
                    Member::query()->create($values);
                    $inserted++;
                } else {
                    // Don't overwrite a richer realm name backfilled by
                    // RIO - we only own realm_slug here. Don't clobber
                    // class/race/rank_name either; GRM is the source.
                    $member->fill($values)->save();
                    $updated++;
                }

                $seenIds[] = $entry['character_id'];
            }
        });

        $notSeen = Member::query()
            ->forGuild($this->guildKey)
            ->active()
            ->whereNotNull('blizzard_character_id')
            ->when(
                $seenIds !== [],
                fn ($q) => $q->whereNotIn('blizzard_character_id', $seenIds),
            )
            ->count();

        Log::info('blizzard guild roster pulled', [
            'guild' => "{$this->guildNameSlug}-{$this->guildRealmSlug}",
            'rows' => count($rows),
            'inserted' => $inserted,
            'updated' => $updated,
            'not_seen_this_pull' => $notSeen,
        ]);

        return [
            'inserted' => $inserted,
            'updated' => $updated,
            'total_in_roster' => count($rows),
            'not_seen_this_pull' => $notSeen,
            'pulled_at' => $now->toIso8601String(),
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array{
     *   name:string,
     *   character_id:int,
     *   realm_slug:string,
     *   level:?int,
     *   rank_index:?int,
     *   faction:?string,
     * }|null
     */
    private function extract(array $row): ?array
    {
        $character = $row['character'] ?? null;
        if (! is_array($character)) {
            return null;
        }
        $charName = is_string($character['name'] ?? null) ? $character['name'] : null;
        $charId = isset($character['id']) && is_numeric($character['id']) ? (int) $character['id'] : null;
        $realm = is_array($character['realm'] ?? null) ? $character['realm'] : null;
        $realmSlug = is_array($realm) && is_string($realm['slug'] ?? null) ? $realm['slug'] : null;
        $realmName = is_array($realm) && is_string($realm['name'] ?? null) ? $realm['name'] : null;

        if ($charName === null || $charName === '' || $charId === null || $realmSlug === null || $realmName === null) {
            return null;
        }

        // GRM stores names as "Char-CollapsedRealm" (no spaces). Match
        // that so the (guild_key, name) lookup hits existing rows.
        $collapsedRealm = str_replace(' ', '', $realmName);
        $name = "{$charName}-{$collapsedRealm}";

        $level = isset($character['level']) && is_numeric($character['level'])
            ? (int) $character['level']
            : null;

        $rankIndex = isset($row['rank']) && is_numeric($row['rank'])
            ? (int) $row['rank']
            : null;

        $faction = null;
        if (is_array($character['faction'] ?? null)) {
            $factionType = $character['faction']['type'] ?? null;
            if (is_string($factionType) && $factionType !== '') {
                // Blizzard ships "ALLIANCE" / "HORDE"; GRM stores
                // "Alliance" / "Horde". Normalise to GRM's casing so
                // mixed-source reads stay consistent.
                $faction = ucfirst(strtolower($factionType));
            }
        }

        return [
            'name' => $name,
            'character_id' => $charId,
            'realm_slug' => $realmSlug,
            'level' => $level,
            'rank_index' => $rankIndex,
            'faction' => $faction,
        ];
    }

    private function resolveMember(int $characterId, string $name): ?Member
    {
        $byId = Member::query()
            ->forGuild($this->guildKey)
            ->where('blizzard_character_id', $characterId)
            ->first();
        if ($byId !== null) {
            return $byId;
        }

        return Member::query()
            ->forGuild($this->guildKey)
            ->where('name', $name)
            ->first();
    }
}
