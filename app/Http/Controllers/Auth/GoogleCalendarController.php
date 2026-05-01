<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Jobs\SyncEventToGoogleJob;
use App\Models\RaidEvent;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * OAuth handshake for the shared Google Calendar push. One officer is
 * authorised at a time; the connecting officer's row carries the
 * calendar id, refresh token, and is the one User::googleConnector()
 * resolves on every sync job.
 *
 * Every failure mode (state mismatch, code missing, exchange failure,
 * userinfo fetch failure, calendar create failure) flashes a specific
 * reason on /admin/google-calendar AND logs with structured context.
 * The user's "nothing fails silently" rule is the load-bearing
 * principle here - blank "did it work?" states are not acceptable.
 */
class GoogleCalendarController extends Controller
{
    private const STATE_KEY = 'google_calendar_oauth_state';

    public function start(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->isOfficerTier(), 403);

        $client = GoogleCalendarClient::fromConfig();
        if (! $client->isConfigured()) {
            return redirect()
                ->route('admin.google-calendar.index')
                ->withErrors(['google_calendar' => 'Google Calendar OAuth is not configured. Set GOOGLE_CALENDAR_CLIENT_ID, GOOGLE_CALENDAR_CLIENT_SECRET and GOOGLE_CALENDAR_REDIRECT_URI in .env.']);
        }

        $state = Str::random(40);
        $request->session()->put(self::STATE_KEY, $state);

        return redirect()->away($client->authUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->isOfficerTier(), 403);

        $back = redirect()->route('admin.google-calendar.index');

        if ($request->filled('error')) {
            $err = (string) $request->query('error');
            Log::warning('Google Calendar OAuth callback returned error', ['error' => $err]);

            return $back->withErrors(['google_calendar' => "Google denied the consent: {$err}"]);
        }

        $state = (string) $request->query('state', '');
        $expected = (string) $request->session()->pull(self::STATE_KEY, '');
        if ($state === '' || $expected === '' || ! hash_equals($expected, $state)) {
            Log::warning('Google Calendar OAuth state mismatch', ['expected_present' => $expected !== '']);

            return $back->withErrors(['google_calendar' => 'OAuth state mismatch. Try connecting again.']);
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            return $back->withErrors(['google_calendar' => 'Google did not return an authorisation code. Try again.']);
        }

        $client = GoogleCalendarClient::fromConfig();
        if (! $client->isConfigured()) {
            return $back->withErrors(['google_calendar' => 'Google Calendar OAuth is not configured.']);
        }

        try {
            $tokens = $client->exchangeCode($code);
        } catch (\Throwable $e) {
            Log::warning('Google Calendar token exchange failed', ['message' => $e->getMessage()]);

            return $back->withErrors(['google_calendar' => $e->getMessage()]);
        }

        $user = $request->user();

        // Stage tokens onto the connector before creating the calendar
        // so refreshAccessTokenIfStale() inside createDedicatedCalendar
        // has something to work with.
        DB::transaction(function () use ($user, $tokens) {
            User::query()
                ->where('id', '!=', $user->id)
                ->whereNotNull('google_calendar_connected_at')
                ->update([
                    'google_refresh_token' => null,
                    'google_access_token' => null,
                    'google_token_expires_at' => null,
                    'google_calendar_id' => null,
                    'google_calendar_connected_at' => null,
                    'google_email' => null,
                ]);

            $user->google_refresh_token = $tokens['refresh_token'];
            $user->google_access_token = $tokens['access_token'];
            $user->google_token_expires_at = $tokens['expires_at'];
            $user->google_email = $tokens['email'];
            // Tentatively mark connected; confirmed once the calendar
            // is created. If calendar creation fails we null this back
            // out so the admin UI doesn't show a half-config as
            // "connected".
            $user->google_calendar_connected_at = now();
            $user->save();
        });

        try {
            $calendarId = $client->createDedicatedCalendar($user);
            $user->google_calendar_id = $calendarId;
            $user->save();
        } catch (\Throwable $e) {
            Log::warning('Google Calendar create failed', ['message' => $e->getMessage()]);
            // Roll back the partial connection so the admin UI flips
            // back to "not connected" and the officer can retry from
            // a clean slate.
            $user->forceFill([
                'google_refresh_token' => null,
                'google_access_token' => null,
                'google_token_expires_at' => null,
                'google_calendar_id' => null,
                'google_calendar_connected_at' => null,
                'google_email' => null,
            ])->save();

            return $back->withErrors(['google_calendar' => 'Got tokens from Google but calendar creation failed: '.$e->getMessage()
                .' Try Connect again. If this keeps happening, confirm the Google Calendar API is enabled on the OAuth client\'s Cloud project.',
            ]);
        }

        // Backfill: queue a sync job for every event in the rolling
        // 7d-back / 90d-forward feed window. Each job is its own queue
        // unit so no single PHP process blocks past Hostinger's 30s
        // budget.
        $queued = 0;
        RaidEvent::query()
            ->withinFeedWindow()
            ->select(['id'])
            ->orderBy('starts_at')
            ->each(function (RaidEvent $event) use (&$queued) {
                SyncEventToGoogleJob::dispatch($event->id, SyncEventToGoogleJob::ACTION_UPSERT);
                $queued++;
            });

        Log::info('Google Calendar connected', [
            'user_id' => $user->id,
            'google_email' => $tokens['email'],
            'calendar_id' => $calendarId,
            'queued_backfill' => $queued,
        ]);

        $msg = "Connected as {$tokens['email']}. Calendar created.";
        if ($queued > 0) {
            $msg .= " Queued {$queued} existing event(s) for initial sync.";
        }
        $msg .= ' Share the calendar with the rest of the officers from Google Calendar (calendar settings -> Share with specific people or groups).';

        return $back->with('status', $msg);
    }

    public function disconnect(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->isOfficerTier(), 403);

        $user = $request->user();
        if (! $user->google_calendar_connected_at) {
            return redirect()
                ->route('admin.google-calendar.index')
                ->withErrors(['google_calendar' => 'You are not the connected officer; nothing to disconnect.']);
        }

        $user->forceFill([
            'google_refresh_token' => null,
            'google_access_token' => null,
            'google_token_expires_at' => null,
            'google_calendar_id' => null,
            'google_calendar_connected_at' => null,
            'google_email' => null,
        ])->save();

        Log::info('Google Calendar disconnected', ['user_id' => $user->id]);

        return redirect()
            ->route('admin.google-calendar.index')
            ->with('status', 'Disconnected. The Google calendar itself was left in place; delete it from Google Calendar if you no longer want it.');
    }
}
