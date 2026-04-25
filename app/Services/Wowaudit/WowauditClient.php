<?php

namespace App\Services\Wowaudit;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the wowaudit team API. Auth is a single team key
 * (Settings -> API in the wowaudit UI), sent as Authorization: Bearer.
 *
 * Endpoint inventory we actually use:
 *   GET /team                                - team metadata + last refresh
 *   GET /period                              - current Blizzard period id
 *   GET /characters                          - roster list (id, name, realm,
 *                                              class, role, rank, status)
 *   GET /historical_data?period=N            - per-character data for the
 *                                              given period: dungeons_done,
 *                                              world_quests_done, vault_options
 *   GET /historical_data/{characterId}       - full activity history +
 *                                              best_gear (per-slot ilvl)
 */
class WowauditClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            apiKey: (string) config('wowaudit.api_key'),
            baseUrl: rtrim((string) config('wowaudit.base_url', 'https://wowaudit.com/v1'), '/'),
        );
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    private function request()
    {
        return Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'Accept' => 'application/json',
        ])->timeout(20);
    }

    public function team(): Response
    {
        return $this->request()->get("{$this->baseUrl}/team");
    }

    public function period(): Response
    {
        return $this->request()->get("{$this->baseUrl}/period");
    }

    public function characters(): Response
    {
        return $this->request()->get("{$this->baseUrl}/characters");
    }

    public function historicalDataForPeriod(int $period): Response
    {
        return $this->request()
            ->get("{$this->baseUrl}/historical_data", ['period' => $period]);
    }

    public function characterHistory(int $characterId): Response
    {
        return $this->request()
            ->get("{$this->baseUrl}/historical_data/{$characterId}");
    }
}
