<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Services\Discord\EventAnnouncer;
use App\Services\RaidHelper\EventUpserter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives event.create / event.edit / event.delete pushes from
 * Raid-Helper. Auth is handled by the RaidHelperWebhookAuth middleware
 * upstream; this controller just upserts.
 *
 * Per the docs, Raid-Helper considers any 200 response as "delivered" -
 * we therefore return 200 even on no-op (event already in the
 * expected state) so it doesn't retry needlessly.
 */
class RaidHelperController extends Controller
{
    public function handle(Request $request, EventUpserter $upserter, EventAnnouncer $announcer): JsonResponse
    {
        $payload = $request->json()->all();
        if (! is_array($payload) || empty($payload['id'])) {
            return response()->json(['error' => 'missing event id'], 400);
        }

        // event.delete sends the same payload shape as create/edit but
        // expects us to soft-delete the local cache. Webhook events are
        // not labelled in the body (the subscription URL or a webhook
        // event-name header would have been nicer); we infer from the
        // request path the caller registered. For us, the URL is just
        // /api/webhook/raidhelper - so we rely on the absence of fields
        // that only live events have... actually the API docs show all
        // three webhook flavours sending the SAME body. Distinguishing
        // create vs edit vs delete requires Raid-Helper to add an event-
        // type indicator. Until then: upsert always, never soft-delete
        // from a webhook. The /events/{id}/delete dashboard action is
        // what soft-deletes locally; the webhook just keeps the cache
        // fresh.
        $event = $upserter->upsert($payload);

        // First-time-seen events trigger an outbound announce post to
        // the configured event_announce webhook(s). wasRecentlyCreated
        // is true only on the firstOrCreate insert; subsequent edits
        // come back as updates and don't re-announce. No-op when no
        // matching webhook is configured.
        if ($event->wasRecentlyCreated) {
            $announcer->announceNew($event);
        }

        return response()->json([
            'snapshot_id' => $event->id,
            'ics_sequence' => $event->ics_sequence,
        ], 200);
    }
}
