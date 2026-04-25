<?php

namespace App\Http\Controllers\Events;

use App\Http\Controllers\Controller;
use App\Models\RaidEvent;
use App\Services\RaidHelper\EventUpserter;
use App\Services\RaidHelper\RaidHelperClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class EventController extends Controller
{
    public function index(): View
    {
        abort_unless(auth()->user()?->can('events.create'), 403);

        return view('events.index', [
            'upcoming' => RaidEvent::query()->upcoming()->limit(50)->get(),
            'past' => RaidEvent::query()
                ->where('starts_at', '<', now())
                ->orderByDesc('starts_at')
                ->limit(20)
                ->get(),
        ]);
    }

    public function create(): View
    {
        abort_unless(auth()->user()?->can('events.create'), 403);

        return view('events.create', [
            'templates' => config('raidhelper.templates'),
            'channels' => config('raidhelper.channels', []),
            'defaultChannel' => config('raidhelper.default_channel_id'),
            'leaderId' => auth()->user()?->discord_id,
        ]);
    }

    public function store(Request $request, RaidHelperClient $client, EventUpserter $upserter): RedirectResponse
    {
        abort_unless(auth()->user()?->can('events.create'), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'starts_at' => ['required', 'date'],
            // Three valid duration modes:
            //   duration  -> required duration_minutes, ends_at must be empty
            //   end_time  -> required ends_at after starts_at, duration_minutes empty
            //   default   -> both empty, Raid-Helper applies its server default
            'duration_mode' => ['required', 'in:duration,end_time,default'],
            'duration_minutes' => ['nullable', 'required_if:duration_mode,duration', 'integer', 'min:15', 'max:1440'],
            'ends_at' => ['nullable', 'required_if:duration_mode,end_time', 'date', 'after:starts_at'],
            'template_id' => ['required', 'string'],
            // Channel can come from the dropdown OR a pasted ID via the
            // "Other..." path, hence the looser validation here.
            'channel_id' => ['required', 'string', 'regex:/^\d{15,25}$/'],
            'leader_id' => ['required', 'string'],
            'mentions' => ['nullable', 'string', 'max:200'],
        ], [
            'channel_id.regex' => 'Channel ID must be the numeric Discord snowflake (15-25 digits).',
            'ends_at.after' => 'End time must be after the start time.',
        ]);

        $startsAt = CarbonImmutable::parse($validated['starts_at'], config('raidhelper.timezone'));

        // Compute the duration that goes to Raid-Helper. The API only
        // accepts advancedSettings.duration (in minutes); end_time mode
        // converts to the equivalent duration server-side.
        $durationMinutes = match ($validated['duration_mode']) {
            'duration' => (int) $validated['duration_minutes'],
            'end_time' => (int) round(
                (CarbonImmutable::parse($validated['ends_at'], config('raidhelper.timezone'))->getTimestamp() - $startsAt->getTimestamp()) / 60
            ),
            default => null,
        };

        $advancedSettings = [];
        if ($durationMinutes !== null) {
            $advancedSettings['duration'] = (string) $durationMinutes;
        }

        $payload = [
            'leaderId' => $validated['leader_id'],
            'templateId' => $validated['template_id'],
            'date' => $startsAt->format('d-m-Y'),
            'time' => $startsAt->format('H:i'),
            'title' => $validated['title'],
            'description' => $validated['description'] ?? '',
        ];
        if (! empty($advancedSettings)) {
            $payload['advancedSettings'] = $advancedSettings;
        }

        $resp = $client->createEvent($validated['channel_id'], $payload);

        if (! $resp->successful()) {
            Log::warning('Raid-Helper event create failed', [
                'status' => $resp->status(),
                'body' => mb_substr($resp->body(), 0, 500),
            ]);
            return back()
                ->withInput()
                ->withErrors(['raidhelper' => $this->humaniseRaidHelperError($resp, $validated['channel_id'])]);
        }

        $body = $resp->json();
        $eventPayload = $body['event'] ?? null;
        if (! is_array($eventPayload) || empty($eventPayload['id'])) {
            return back()->withErrors(['raidhelper' => 'Raid-Helper returned an unexpected response shape.']);
        }

        $event = $upserter->upsert($eventPayload);

        return redirect()
            ->route('events.show', $event)
            ->with('status', 'Event posted to Discord.');
    }

    public function show(RaidEvent $event): View
    {
        abort_unless(auth()->user()?->can('events.create'), 403);

        return view('events.show', [
            'event' => $event,
            'icsUrl' => route('event.ics', ['event' => $event, 'sig' => $this->signedIcsToken($event)]),
            'jumpUrl' => $event->discordJumpUrl(),
            'webcalUrl' => $this->webcalUrl(),
        ]);
    }

    public function destroy(RaidEvent $event, RaidHelperClient $client): RedirectResponse
    {
        abort_unless(auth()->user()?->can('events.delete'), 403);

        $resp = $client->deleteEvent($event->raidhelper_event_id);
        if (! $resp->successful() && $resp->status() !== 404) {
            Log::warning('Raid-Helper delete failed', ['status' => $resp->status()]);
            return back()->withErrors(['raidhelper' => "Raid-Helper delete returned {$resp->status()}."]);
        }
        $event->delete();

        return redirect()
            ->route('events.index')
            ->with('status', 'Event deleted.');
    }

    private function signedIcsToken(RaidEvent $event): string
    {
        return hash_hmac('sha256', $event->ics_uid . '|' . $event->ics_sequence, config('app.key'));
    }

    /**
     * Turn Raid-Helper's HTTP error response into something readable.
     * Pulls the JSON `title` field when present (Javalin's standard
     * problem-detail shape - Raid-Helper sends a JSON body like
     * {"title": "Endpoint POST ... not found", "status": 404, ...}),
     * and adds an extra hint for 404s, which in practice always mean
     * "Raid-Helper can't see that channel" - either the ID is wrong
     * or the bot isn't in it.
     */
    private function humaniseRaidHelperError(\Illuminate\Http\Client\Response $resp, string $channelId): string
    {
        $title = $resp->json('title');
        $reason = is_string($title) && $title !== ''
            ? $title
            : mb_substr($resp->body(), 0, 200);

        $msg = "Raid-Helper rejected the request ({$resp->status()}): {$reason}";

        if ($resp->status() === 404) {
            $msg .= " — channel {$channelId} likely isn't accessible. Check the ID is right (right-click channel in Discord with Developer Mode on -> Copy ID), and make sure the Raid-Helper bot has Send Messages + Embed Links + Add Reactions permissions in that channel.";
        }

        return $msg;
    }

    private function webcalUrl(): string
    {
        $token = auth()->user()->ensureCalendarToken();
        $https = route('calendar.subscription', ['token' => $token]);
        return preg_replace('#^https?://#', 'webcal://', $https);
    }
}
