<?php

namespace App\Services\Wcl;

use App\Models\WclDeath;
use App\Models\WclFight;
use App\Models\WclReport;
use Illuminate\Support\Collection;

/**
 * Read-side aggregator for the wcl_deaths table. Powers a "what
 * killed people" widget: top abilities by death count over the most
 * recent N raid nights, grouped by encounter, with kill / wipe split
 * so officers can tell "wipes are still on Spike, but kills go clean"
 * apart from "we wipe AND kill through the same mechanic".
 *
 * Pure read side: no writes, no scraping. Safe to call from a
 * dashboard render path.
 */
class DeathCauseAggregator
{
    public function __construct(private readonly string $guildKey)
    {
    }

    /**
     * Top killing abilities per encounter across the most recent N
     * reports for this guild. Encounters with zero deaths are dropped
     * so the widget renders no empty rows.
     *
     * @return list<array{
     *   encounter_id:int,
     *   encounter_name:string,
     *   total_deaths:int,
     *   abilities: list<array{
     *     ability_id:?int,
     *     ability_name:string,
     *     ability_icon:?string,
     *     deaths:int,
     *     deaths_on_kills:int,
     *     deaths_on_wipes:int,
     *   }>
     * }>
     */
    public function topByEncounter(int $reportLimit = 5, int $abilitiesPerEncounter = 5): array
    {
        $reportIds = WclReport::query()
            ->where('guild_key', $this->guildKey)
            ->whereNotNull('fights_imported_at')
            ->orderByDesc('start_time')
            ->limit($reportLimit)
            ->pluck('id');
        if ($reportIds->isEmpty()) {
            return [];
        }

        $fights = WclFight::query()
            ->whereIn('wcl_report_id', $reportIds)
            ->get(['id', 'encounter_id', 'name', 'kill']);
        if ($fights->isEmpty()) {
            return [];
        }

        $fightById = $fights->keyBy('id');
        $deaths = WclDeath::query()
            ->whereIn('wcl_fight_id', $fightById->keys())
            ->get(['wcl_fight_id', 'killing_ability_id', 'killing_ability_name', 'killing_ability_icon']);
        if ($deaths->isEmpty()) {
            return [];
        }

        $encounters = $this->buildEncounterBuckets($fightById, $deaths);

        // Trim each encounter's ability list to the top N by death
        // count, then drop encounters that ended up empty.
        $out = [];
        foreach ($encounters as $bucket) {
            $abilities = collect($bucket['abilities'])
                ->sortByDesc('deaths')
                ->take($abilitiesPerEncounter)
                ->values()
                ->all();
            if ($abilities === []) {
                continue;
            }
            $out[] = [
                'encounter_id' => $bucket['encounter_id'],
                'encounter_name' => $bucket['encounter_name'],
                'total_deaths' => $bucket['total_deaths'],
                'abilities' => $abilities,
            ];
        }

        // Sort encounters by total deaths desc so the widget leads
        // with the loudest cause of death across the raid week.
        usort($out, static fn ($a, $b) => $b['total_deaths'] <=> $a['total_deaths']);

        return $out;
    }

    /**
     * @param  Collection<int, WclFight>  $fightById
     * @param  Collection<int, WclDeath>  $deaths
     * @return array<int, array{
     *   encounter_id:int, encounter_name:string, total_deaths:int,
     *   abilities: array<string, array{
     *     ability_id:?int, ability_name:string, ability_icon:?string,
     *     deaths:int, deaths_on_kills:int, deaths_on_wipes:int,
     *   }>
     * }>
     */
    private function buildEncounterBuckets(Collection $fightById, Collection $deaths): array
    {
        $encounters = [];
        foreach ($deaths as $d) {
            $fight = $fightById->get($d->wcl_fight_id);
            if ($fight === null) {
                continue;
            }
            $encId = (int) $fight->encounter_id;
            if (! isset($encounters[$encId])) {
                $encounters[$encId] = [
                    'encounter_id' => $encId,
                    'encounter_name' => (string) $fight->name,
                    'total_deaths' => 0,
                    'abilities' => [],
                ];
            }
            $encounters[$encId]['total_deaths']++;

            // Bucket abilities by name when we have it, falling back
            // to "Unknown" so the widget never shows an empty row.
            // Name is the human-readable axis; the canonical guid is
            // kept on each bucket so a future drill-down can link to
            // Wowhead / WCL ability pages.
            $name = $d->killing_ability_name ?: 'Unknown';
            $key = mb_strtolower($name);
            if (! isset($encounters[$encId]['abilities'][$key])) {
                $encounters[$encId]['abilities'][$key] = [
                    'ability_id' => $d->killing_ability_id !== null ? (int) $d->killing_ability_id : null,
                    'ability_name' => $name,
                    'ability_icon' => $d->killing_ability_icon,
                    'deaths' => 0,
                    'deaths_on_kills' => 0,
                    'deaths_on_wipes' => 0,
                ];
            }
            $encounters[$encId]['abilities'][$key]['deaths']++;
            if ($fight->kill) {
                $encounters[$encId]['abilities'][$key]['deaths_on_kills']++;
            } else {
                $encounters[$encId]['abilities'][$key]['deaths_on_wipes']++;
            }
        }

        return $encounters;
    }
}
