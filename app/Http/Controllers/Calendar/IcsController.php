<?php

namespace App\Http\Controllers\Calendar;

use App\Http\Controllers\Controller;
use App\Models\RaidEvent;
use App\Models\User;
use App\Services\Calendar\IcsBuilder;
use App\Services\WorldEvents\WorldEventsCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Two endpoints for two consumers:
 *
 *   GET /events/{event}.ics?sig=...  -> single event, sharable signed URL
 *   GET /calendar/{token}.ics        -> rolling 90-day feed for one user,
 *                                       used as the webcal:// subscription
 *                                       URL in Google Calendar
 *
 * The single-event endpoint uses an HMAC of (ics_uid + ics_sequence)
 * which means an edited event invalidates old links automatically.
 * The subscription endpoint uses a stable random token on the User row
 * that the user can rotate from a settings page if it leaks.
 *
 * Caching: Cache-Control: max-age=300 + ETag(content fingerprint, with
 * DTSTAMP stripped so re-renders at different seconds produce the same
 * etag) so Google Calendar's polling is cheap.
 */
class IcsController extends Controller
{
    /**
     * SHA-256 of the body with DTSTAMP lines stripped. DTSTAMP is the
     * only time-varying content in the rendered ICS, so removing it
     * gives an etag that fingerprints the data, not the rendering
     * moment - otherwise every request a second apart looks "new" to
     * the client and the 304 path never fires.
     */
    private function stableEtag(string $body): string
    {
        $stable = preg_replace('/^DTSTAMP:[^\r\n]*\r?\n?/m', '', $body);
        return '"' . hash('sha256', (string) $stable) . '"';
    }

    public function show(Request $request, RaidEvent $event, IcsBuilder $builder): Response
    {
        $expected = hash_hmac('sha256', $event->ics_uid . '|' . $event->ics_sequence, config('app.key'));
        if (! hash_equals($expected, (string) $request->query('sig'))) {
            abort(403, 'invalid signature');
        }

        return response($builder->buildOne($event), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="regenesis-' . $event->id . '.ics"',
        ]);
    }

    /**
     * Combined Social feed: the user's raid events plus computed world
     * events (Darkmoon Faire, holidays, Trading Post resets) so a single
     * subscription gives the holistic Social-page picture. Existing
     * /calendar/{token}.ics stays raid-only so anyone already subscribed
     * doesn't suddenly see new entries.
     */
    public function socialSubscription(Request $request, string $token, IcsBuilder $builder, WorldEventsCalendar $worldCalendar): Response
    {
        $user = User::query()->where('calendar_token', $token)->first();
        if (! $user) {
            abort(404);
        }

        $raidEvents = RaidEvent::query()->withinFeedWindow()->orderBy('starts_at')->get();

        // 60-day forward-only window for world events keeps the feed
        // bounded; users see Darkmoon Faire / Brewfest / etc. as they
        // approach without piling up the rest of the year up front.
        $now = CarbonImmutable::now();
        $worldEvents = $worldCalendar->eventsInRange($now, $now->addDays(60));

        $body = $builder->buildSocialFeed($raidEvents, $worldEvents);
        $latestUpdate = $raidEvents->max('updated_at');
        $etag = $this->stableEtag($body);

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, [
                'ETag' => $etag,
                'Cache-Control' => 'private, max-age=300',
            ]);
        }

        $headers = [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Cache-Control' => 'private, max-age=300',
            'ETag' => $etag,
        ];
        if ($latestUpdate) {
            $headers['Last-Modified'] = $latestUpdate->toRfc7231String();
        }

        return response($body, 200, $headers);
    }

    /**
     * Public, no-auth world-events feed: Darkmoon Faire, Trading Post
     * resets, fixed-date holidays. No per-user content so no token
     * needed. Anyone with the URL can subscribe in their calendar app.
     */
    public function worldFeed(Request $request, IcsBuilder $builder, WorldEventsCalendar $worldCalendar): Response
    {
        $now = CarbonImmutable::now();
        // Year-ahead window: a casual subscriber wants to see when
        // every holiday is across the year, not just the next 60 days.
        $worldEvents = $worldCalendar->eventsInRange($now, $now->addDays(365));

        $body = $builder->buildSocialFeed([], $worldEvents);
        $etag = $this->stableEtag($body);

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, [
                'ETag' => $etag,
                'Cache-Control' => 'public, max-age=86400',  // a day; world events shift slowly
            ]);
        }

        return response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Cache-Control' => 'public, max-age=86400',
            'ETag' => $etag,
        ]);
    }

    public function subscription(Request $request, string $token, IcsBuilder $builder): Response
    {
        $user = User::query()->where('calendar_token', $token)->first();
        if (! $user) {
            abort(404);
        }

        // Rolling 90-day window. Includes recent past so calendar clients
        // can show a "what was today" view without us shipping every
        // event we've ever known.
        $events = RaidEvent::query()->withinFeedWindow()->orderBy('starts_at')->get();

        $body = $builder->buildFeed($events);
        $latestUpdate = $events->max('updated_at');
        $etag = $this->stableEtag($body);

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, [
                'ETag' => $etag,
                'Cache-Control' => 'private, max-age=300',
            ]);
        }

        $headers = [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Cache-Control' => 'private, max-age=300',
            'ETag' => $etag,
        ];
        if ($latestUpdate) {
            $headers['Last-Modified'] = $latestUpdate->toRfc7231String();
        }

        return response($body, 200, $headers);
    }
}
