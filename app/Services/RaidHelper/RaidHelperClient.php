<?php

namespace App\Services\RaidHelper;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the Raid-Helper REST API. Authentication is the
 * server-scope key (`/apikey` in Discord) - NOT the user-scope key
 * (`/usersettings apikey`), which only authorises GET /users/.../events.
 *
 * Endpoints documented at https://raid-helper.xyz/documentation/api.
 *
 * Each method returns the raw Response so callers can inspect status
 * codes when needed; convenience accessors (->json(), ->successful())
 * keep the call sites readable.
 */
class RaidHelperClient
{
    private const BASE = 'https://raid-helper.dev/api';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $serverId,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            apiKey: (string) config('raidhelper.api_key'),
            serverId: (string) config('raidhelper.server_id'),
        );
    }

    private function request()
    {
        return Http::withHeaders([
            'Authorization' => $this->apiKey,
            'Accept' => 'application/json',
        ])->timeout(15);
    }

    /**
     * POST /api/v2/servers/{server}/channels/{channel}/event
     *
     * @param  array<string,mixed>  $payload
     */
    public function createEvent(string $channelId, array $payload): Response
    {
        return $this->request()
            ->asJson()
            ->post(self::BASE . "/v2/servers/{$this->serverId}/channels/{$channelId}/event", $payload);
    }

    /**
     * PATCH /api/v2/events/{eventId}
     *
     * @param  array<string,mixed>  $payload
     */
    public function updateEvent(string $eventId, array $payload): Response
    {
        return $this->request()
            ->asJson()
            ->patch(self::BASE . "/v2/events/{$eventId}", $payload);
    }

    /**
     * DELETE /api/v2/events/{eventId}
     */
    public function deleteEvent(string $eventId): Response
    {
        return $this->request()
            ->delete(self::BASE . "/v2/events/{$eventId}");
    }

    /**
     * GET /api/v2/events/{eventId}
     *
     * Per the docs, this single-event GET does NOT require auth. We
     * still send the header so callers can use one client.
     */
    public function getEvent(string $eventId): Response
    {
        return $this->request()
            ->get(self::BASE . "/v2/events/{$eventId}");
    }

    /**
     * GET /api/v3/servers/{server}/events?Page=N&IncludeSignUps=true
     *
     * @param  array<string,scalar|null>  $headers
     */
    public function listEvents(int $page = 1, bool $includeSignUps = false, ?string $channelFilter = null): Response
    {
        $headers = ['Page' => (string) $page];
        if ($includeSignUps) {
            $headers['IncludeSignUps'] = 'true';
        }
        if ($channelFilter) {
            $headers['ChannelFilter'] = $channelFilter;
        }
        return $this->request()
            ->withHeaders($headers)
            ->get(self::BASE . "/v3/servers/{$this->serverId}/events");
    }

    /**
     * GET /api/v3/servers/{server}/scheduledevents
     */
    public function listScheduledEvents(): Response
    {
        return $this->request()
            ->get(self::BASE . "/v3/servers/{$this->serverId}/scheduledevents");
    }

    /**
     * GET /api/v2/servers/{server}/attendance
     *
     * Time filters / channel filters / tag filter all go through HEADERS
     * per the documented quirk - NOT the query string.
     */
    public function attendance(?int $start = null, ?int $end = null, ?string $tagFilter = null, ?string $channelFilter = null): Response
    {
        $headers = [];
        if ($start) {
            $headers['TimeFilterStart'] = (string) $start;
        }
        if ($end) {
            $headers['TimeFilterEnd'] = (string) $end;
        }
        if ($tagFilter) {
            $headers['TagFilter'] = $tagFilter;
        }
        if ($channelFilter) {
            $headers['ChannelFilter'] = $channelFilter;
        }

        return $this->request()
            ->withHeaders($headers)
            ->get(self::BASE . "/v2/servers/{$this->serverId}/attendance");
    }
}
