<?php

namespace App\Http\Controllers\Calendar;

use App\Http\Controllers\Controller;
use App\Models\RaidEvent;
use App\Models\User;
use App\Services\Calendar\IcsBuilder;
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
 * Caching: Cache-Control: max-age=300 + ETag(MAX(updated_at)) so Google
 * Calendar's polling is cheap.
 */
class IcsController extends Controller
{
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
        $etag = '"' . hash('sha256', $body) . '"';

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
