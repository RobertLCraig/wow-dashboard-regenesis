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
            'duration_minutes' => ['required', 'integer', 'min:15', 'max:600'],
            'template_id' => ['required', 'string'],
            'channel_id' => ['required', 'string'],
            'leader_id' => ['required', 'string'],
            'mentions' => ['nullable', 'string', 'max:200'],
        ]);

        $startsAt = CarbonImmutable::parse($validated['starts_at'], config('raidhelper.timezone'));

        $payload = [
            'leaderId' => $validated['leader_id'],
            'templateId' => $validated['template_id'],
            'date' => $startsAt->format('d-m-Y'),
            'time' => $startsAt->format('H:i'),
            'title' => $validated['title'],
            'description' => $validated['description'] ?? '',
            'advancedSettings' => [
                'duration' => (string) $validated['duration_minutes'],
            ],
        ];

        $resp = $client->createEvent($validated['channel_id'], $payload);

        if (! $resp->successful()) {
            Log::warning('Raid-Helper event create failed', [
                'status' => $resp->status(),
                'body' => mb_substr($resp->body(), 0, 500),
            ]);
            return back()
                ->withInput()
                ->withErrors(['raidhelper' => "Raid-Helper rejected the request ({$resp->status()}): " . mb_substr($resp->body(), 0, 200)]);
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
        // Token derives from the event UID + ics_sequence so editing the
        // event invalidates old links automatically.
        return hash_hmac('sha256', $event->ics_uid . '|' . $event->ics_sequence, config('app.key'));
    }

    private function webcalUrl(): string
    {
        $token = auth()->user()->ensureCalendarToken();
        $https = route('calendar.subscription', ['token' => $token]);
        return preg_replace('#^https?://#', 'webcal://', $https);
    }
}
