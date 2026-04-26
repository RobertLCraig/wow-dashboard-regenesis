<?php

namespace App\Services\Raiderio;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the public Raider.IO character API. No auth needed
 * (it's public read-only data), but we are well-behaved about rate limits
 * by pacing calls in the importer rather than at the client level.
 *
 * Endpoint inventory:
 *   GET /characters/profile?region=eu&realm=<slug>&name=<name>&fields=<csv>
 *     Returns a single character's profile. Fields are opt-in via the
 *     `fields` param (see config/raiderio.php for the set we ask for).
 */
class RaiderioClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $region,
        /** @var list<string> */
        private readonly array $defaultFields,
        private readonly int $timeoutSeconds = 10,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            baseUrl: rtrim((string) config('raiderio.base_url', 'https://raider.io/api/v1'), '/'),
            region: (string) config('raiderio.region', 'eu'),
            defaultFields: (array) config('raiderio.profile_fields', []),
            timeoutSeconds: (int) config('raiderio.timeout', 10),
        );
    }

    /**
     * Pull one character's profile. Returns the raw Response so callers
     * can branch on status (404 for unknown chars is normal and not an
     * error condition - characters get renamed/transferred all the time).
     *
     * @param  list<string>|null  $fields  Override the default field set
     */
    public function profile(string $realmSlug, string $name, ?array $fields = null): Response
    {
        $f = $fields ?? $this->defaultFields;
        return Http::acceptJson()
            ->timeout($this->timeoutSeconds)
            ->get("{$this->baseUrl}/characters/profile", [
                'region' => $this->region,
                'realm' => $realmSlug,
                'name' => $name,
                'fields' => implode(',', $f),
            ]);
    }
}
