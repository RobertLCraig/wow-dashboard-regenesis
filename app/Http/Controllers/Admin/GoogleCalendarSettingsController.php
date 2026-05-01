<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RaidEvent;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarClient;
use App\Services\Sync\SyncStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Admin landing page for the shared Google Calendar push integration.
 * Shows: configured / not-configured / connected state, the connecting
 * officer, the calendar id, and the latest SyncStatus row. Provides
 * Connect / Disconnect / Test actions.
 */
class GoogleCalendarSettingsController extends Controller
{
    public function index(): View
    {
        abort_unless(auth()->user()?->isOfficerTier(), 403);

        $client = GoogleCalendarClient::fromConfig();
        $connector = User::googleConnector();
        $state = SyncStatus::get(SyncStatus::SOURCE_GOOGLE_CAL);

        $eventCountInWindow = RaidEvent::query()->withinFeedWindow()->count();
        $eventsTracked = RaidEvent::query()->whereNotNull('google_calendar_event_id')->count();

        return view('admin.google-calendar.index', [
            'isConfigured' => $client->isConfigured(),
            'connector' => $connector,
            'isConnectedAsMe' => $connector !== null && $connector->id === auth()->id(),
            'state' => $state,
            'eventCountInWindow' => $eventCountInWindow,
            'eventsTracked' => $eventsTracked,
        ]);
    }

    /**
     * Probe the connection by listing the calendar (no events created
     * or deleted; calendars.get is the lightest authenticated call).
     * Surfaces the result as a flash so officers can sanity-check
     * without waiting for a real event sync.
     */
    public function test(): RedirectResponse
    {
        abort_unless(auth()->user()?->isOfficerTier(), 403);
        $back = redirect()->route('admin.google-calendar.index');

        $connector = User::googleConnector();
        if ($connector === null) {
            return $back->withErrors(['google_calendar' => 'No officer is connected. Click Connect first.']);
        }

        $client = GoogleCalendarClient::fromConfig();
        if (! $client->isConfigured()) {
            return $back->withErrors(['google_calendar' => 'Google Calendar OAuth is not configured.']);
        }

        try {
            $items = $client->listEvents(
                $connector,
                CarbonImmutable::now()->subDays(7),
                CarbonImmutable::now()->addDays(90),
            );
        } catch (\Throwable $e) {
            Log::warning('Google Calendar test failed', ['message' => $e->getMessage()]);

            return $back->withErrors(['google_calendar' => 'Test failed: '.$e->getMessage()]);
        }

        $count = count($items);

        return $back->with('status', "Connection OK. The calendar currently holds {$count} event(s) in the next 90 days.");
    }
}
