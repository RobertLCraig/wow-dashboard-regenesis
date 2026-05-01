<?php

namespace App\Services\GoogleCalendar;

use App\Models\RaidEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over Google's OAuth2 + Calendar v3 REST endpoints. Hand-
 * rolled rather than via google/apiclient because every other external
 * API in this app uses Http:: and the surface we need is small enough
 * (5 endpoints) that the SDK's weight isn't justified.
 *
 * One officer is "the connector" at any given time; the User model's
 * googleConnector() resolves them. All authenticated calls take a User
 * argument so the same client can drive the OAuth-callback path (no
 * connector yet) and the per-event sync path (connector exists).
 *
 * Token refresh handling: refreshAccessTokenIfStale() is called at the
 * top of every authenticated method. Stale = expires within 60s. It
 * persists the new access_token + expires_at back onto the user row.
 *
 * Failure handling lives in the calling Job, not here. This class
 * raises on any non-2xx so the Job can log + write SyncStatus failure
 * + retry per the "nothing fails silently" rule.
 */
class GoogleCalendarClient
{
    private const OAUTH_BASE = 'https://oauth2.googleapis.com';

    private const AUTH_BASE = 'https://accounts.google.com/o/oauth2';

    private const API_BASE = 'https://www.googleapis.com/calendar/v3';

    private const USERINFO = 'https://openidconnect.googleapis.com/v1/userinfo';

    // Calendar = full event read/write on calendars we own. userinfo.email
    // surfaces the connecting officer's Gmail in the admin UI ("connected
    // as foo@gmail.com") so other officers know who the connector is.
    public const SCOPES = [
        'https://www.googleapis.com/auth/calendar',
        'https://www.googleapis.com/auth/userinfo.email',
        'openid',
    ];

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            clientId: (string) config('services.google_calendar.client_id'),
            clientSecret: (string) config('services.google_calendar.client_secret'),
            redirectUri: (string) config('services.google_calendar.redirect'),
        );
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '' && $this->redirectUri !== '';
    }

    /**
     * Build the consent URL the officer is redirected to for the OAuth
     * handshake. access_type=offline + prompt=consent guarantees a
     * refresh token even on a re-authorisation (Google omits the
     * refresh token on subsequent consents otherwise).
     */
    public function authUrl(string $state): string
    {
        $params = http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);

        return self::AUTH_BASE."/v2/auth?{$params}";
    }

    /**
     * Exchange the code returned by the consent screen for a refresh +
     * access token, then fetch the userinfo to capture the Gmail address.
     *
     * @return array{refresh_token:string, access_token:string, expires_at:CarbonImmutable, email:string}
     *
     * @throws \RuntimeException with a human-readable reason on any failure
     */
    public function exchangeCode(string $code): array
    {
        $tokenResp = Http::asForm()
            ->timeout(20)
            ->post(self::OAUTH_BASE.'/token', [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->redirectUri,
            ]);

        if (! $tokenResp->successful()) {
            throw new \RuntimeException($this->describeError('Token exchange failed', $tokenResp));
        }

        $body = (array) $tokenResp->json();
        $refresh = $body['refresh_token'] ?? null;
        $access = $body['access_token'] ?? null;
        $expiresIn = (int) ($body['expires_in'] ?? 0);

        if (! is_string($refresh) || $refresh === '') {
            // Google sometimes withholds the refresh token on re-consent
            // when access_type/prompt aren't both right. We force them
            // above, so this should not happen in practice. Surface
            // explicitly rather than silently storing a half-config.
            throw new \RuntimeException('Google did not return a refresh_token. Try Disconnect, then reconnect with a fresh consent.');
        }
        if (! is_string($access) || $access === '') {
            throw new \RuntimeException('Google did not return an access_token.');
        }

        $userinfoResp = Http::withToken($access)->timeout(20)->get(self::USERINFO);
        if (! $userinfoResp->successful()) {
            throw new \RuntimeException($this->describeError('Userinfo fetch failed', $userinfoResp));
        }
        $email = (string) ($userinfoResp->json('email') ?? '');
        if ($email === '') {
            throw new \RuntimeException('Google did not return an email on the userinfo response.');
        }

        return [
            'refresh_token' => $refresh,
            'access_token' => $access,
            'expires_at' => CarbonImmutable::now()->addSeconds(max(60, $expiresIn - 30)),
            'email' => $email,
        ];
    }

    /**
     * Use the connector's refresh token to mint a new access token. The
     * new token + its expiry are persisted on the user row so other
     * concurrent jobs benefit. Throws on any failure (caller logs +
     * surfaces; on revocation the OAuth callback null-clears the
     * connection so the admin UI flips back to "not connected").
     */
    public function refreshAccessToken(User $user): void
    {
        $refresh = $user->google_refresh_token;
        if (! is_string($refresh) || $refresh === '') {
            throw new \RuntimeException('No google_refresh_token on the connector user; reconnect required.');
        }

        $resp = Http::asForm()->timeout(20)->post(self::OAUTH_BASE.'/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh,
        ]);

        if (! $resp->successful()) {
            throw new \RuntimeException($this->describeError('Refresh token exchange failed', $resp));
        }

        $body = (array) $resp->json();
        $access = $body['access_token'] ?? null;
        $expiresIn = (int) ($body['expires_in'] ?? 0);
        if (! is_string($access) || $access === '') {
            throw new \RuntimeException('Google refresh response did not include access_token.');
        }

        $user->forceFill([
            'google_access_token' => $access,
            'google_token_expires_at' => CarbonImmutable::now()->addSeconds(max(60, $expiresIn - 30)),
        ])->save();
    }

    /**
     * Create the dedicated calendar on first connect. Returns the
     * calendar id Google assigns. Caller persists it on the user row.
     */
    public function createDedicatedCalendar(User $user): string
    {
        $this->refreshAccessTokenIfStale($user);
        $resp = $this->authed($user)->post(self::API_BASE.'/calendars', [
            'summary' => (string) config('services.google_calendar.calendar_name', 'Regenesis Officers'),
            'timeZone' => (string) config('services.google_calendar.timezone', 'Europe/Paris'),
            'description' => 'Shared raid calendar pushed by the Regenesis dashboard. Source of truth lives at '.config('app.url').'.',
        ]);

        if (! $resp->successful()) {
            throw new \RuntimeException($this->describeError('Calendar create failed', $resp));
        }
        $id = $resp->json('id');
        if (! is_string($id) || $id === '') {
            throw new \RuntimeException('Google calendars.insert response missing "id".');
        }

        return $id;
    }

    /**
     * Insert or patch the Google event for a RaidEvent. Returns the
     * Google event id (caller persists it back onto the row to keep
     * the upsert idempotent on subsequent runs).
     */
    public function upsertEvent(User $user, RaidEvent $event): string
    {
        $this->refreshAccessTokenIfStale($user);
        $calendarId = $this->requireCalendarId($user);
        $body = (new GoogleEventBodyBuilder)->build($event);

        if ($event->google_calendar_event_id) {
            $resp = $this->authed($user)->patch(
                self::API_BASE.'/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($event->google_calendar_event_id),
                $body,
            );
            // 404 = the Google event was deleted out from under us
            // (officer cleaned up the calendar manually). Fall through
            // to insert so we self-heal rather than failing forever.
            if ($resp->status() !== 404) {
                if (! $resp->successful()) {
                    throw new \RuntimeException($this->describeError('Event patch failed', $resp));
                }
                $id = $resp->json('id');
                if (! is_string($id) || $id === '') {
                    throw new \RuntimeException('Google events.patch response missing "id".');
                }

                return $id;
            }
        }

        $resp = $this->authed($user)->post(
            self::API_BASE.'/calendars/'.rawurlencode($calendarId).'/events',
            $body,
        );
        if (! $resp->successful()) {
            throw new \RuntimeException($this->describeError('Event insert failed', $resp));
        }
        $id = $resp->json('id');
        if (! is_string($id) || $id === '') {
            throw new \RuntimeException('Google events.insert response missing "id".');
        }

        return $id;
    }

    /**
     * Remove a Google event. 404 / 410 are treated as success - the
     * upstream is already in the desired state.
     */
    public function deleteEvent(User $user, string $googleEventId): void
    {
        $this->refreshAccessTokenIfStale($user);
        $calendarId = $this->requireCalendarId($user);

        $resp = $this->authed($user)->delete(
            self::API_BASE.'/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($googleEventId),
        );

        if (in_array($resp->status(), [200, 204, 404, 410], true)) {
            return;
        }
        throw new \RuntimeException($this->describeError('Event delete failed', $resp));
    }

    /**
     * List events on the dedicated calendar that overlap the given
     * window. Returns each event's google id paired with its
     * extendedProperties.private.regenesis_event_id (or null when the
     * event was created some other way and isn't ours to manage).
     *
     * Used by the daily reconcile cron to spot drift in both directions.
     *
     * @return list<array{google_id:string, regenesis_event_id:?string, summary:?string}>
     */
    public function listEvents(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $this->refreshAccessTokenIfStale($user);
        $calendarId = $this->requireCalendarId($user);

        $items = [];
        $pageToken = null;
        $safety = 0;
        do {
            $params = [
                'timeMin' => $from->toIso8601String(),
                'timeMax' => $to->toIso8601String(),
                'singleEvents' => 'true',
                'maxResults' => 250,
                'showDeleted' => 'false',
            ];
            if ($pageToken !== null) {
                $params['pageToken'] = $pageToken;
            }

            $resp = $this->authed($user)->get(
                self::API_BASE.'/calendars/'.rawurlencode($calendarId).'/events',
                $params,
            );
            if (! $resp->successful()) {
                throw new \RuntimeException($this->describeError('Events list failed', $resp));
            }

            foreach ((array) $resp->json('items', []) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $gid = is_string($item['id'] ?? null) ? $item['id'] : null;
                if ($gid === null) {
                    continue;
                }
                $items[] = [
                    'google_id' => $gid,
                    'regenesis_event_id' => is_string($item['extendedProperties']['private']['regenesis_event_id'] ?? null)
                        ? $item['extendedProperties']['private']['regenesis_event_id']
                        : null,
                    'summary' => is_string($item['summary'] ?? null) ? $item['summary'] : null,
                ];
            }
            $pageToken = is_string($resp->json('nextPageToken')) ? $resp->json('nextPageToken') : null;
            $safety++;
            // Hard cap. The 7d-back / 90d-forward window holds <100 raid
            // events in practice; 50 pages * 250 = 12.5k is a generous
            // ceiling that protects against an infinite loop on bad data.
        } while ($pageToken !== null && $safety < 50);

        return $items;
    }

    private function refreshAccessTokenIfStale(User $user): void
    {
        $expiresAt = $user->google_token_expires_at;
        $access = $user->google_access_token;
        if (! is_string($access) || $access === ''
            || $expiresAt === null
            || CarbonImmutable::parse($expiresAt)->isBefore(CarbonImmutable::now()->addSeconds(60))
        ) {
            $this->refreshAccessToken($user);
            $user->refresh();
        }
    }

    private function authed(User $user)
    {
        $token = $user->google_access_token;

        return Http::withToken((string) $token)
            ->acceptJson()
            ->timeout(20);
    }

    private function requireCalendarId(User $user): string
    {
        $id = $user->google_calendar_id;
        if (! is_string($id) || $id === '') {
            throw new \RuntimeException('Connector user has no google_calendar_id; reconnect required.');
        }

        return $id;
    }

    /**
     * Build a one-line message that includes the HTTP status and Google's
     * error.message field when present, so failure logs and admin
     * flashes carry the actual reason instead of a generic "API failed".
     */
    private function describeError(string $prefix, Response $resp): string
    {
        $msg = $resp->json('error.message');
        if (! is_string($msg) || $msg === '') {
            $msg = mb_substr((string) $resp->body(), 0, 200);
        }

        return "{$prefix}: HTTP {$resp->status()} {$msg}";
    }
}
