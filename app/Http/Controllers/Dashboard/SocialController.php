<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\RaidEvent;
use App\Services\WorldEvents\WorldEventsCalendar;
use Carbon\CarbonImmutable;
use Illuminate\View\View;

/**
 * Guild-wide events hub. Unlike the team dashboards (Heroic/Mythic) and
 * Keynight (M+) which slice members by raid roster, this is a content
 * page: "what's coming up that anyone in the guild might care about?"
 *
 * Two sources stitched into one chronological feed:
 *
 *   - Raid-Helper events from the local raid_events cache (the same
 *     ones that drive /events, but read-only here so members without
 *     events.create can still see them).
 *
 *   - World events from WorldEventsCalendar (Darkmoon Faire today,
 *     Brewfest / Hallow's End / Trading Post reset later).
 *
 * Future phases will add a Discord announcements feed (transmog
 * contests, drunken raid nights) and a calendar-grid view; for now
 * a chronological list grouped by week is the simplest payoff.
 */
class SocialController extends Controller
{
    private const WINDOW_DAYS_AHEAD = 60;

    public function index(WorldEventsCalendar $calendar): View
    {
        abort_unless(auth()->user()?->can('dashboard.social.view'), 403);

        $now = CarbonImmutable::now();
        $until = $now->addDays(self::WINDOW_DAYS_AHEAD);

        $guildEvents = RaidEvent::query()
            ->upcoming()
            ->where('starts_at', '<=', $until)
            ->orderBy('starts_at')
            ->get()
            ->map(fn (RaidEvent $e) => [
                'name' => $e->title ?: 'Raid event',
                'starts_at' => CarbonImmutable::instance($e->starts_at),
                'ends_at' => $e->ends_at ? CarbonImmutable::instance($e->ends_at) : null,
                'kind' => 'guild',
                'tone' => 'sky',
                'description' => $e->description ?: null,
                'discord_url' => $e->discordJumpUrl(),
                'event_url' => route('events.show', ['event' => $e->id]),
            ])
            ->all();

        $worldEvents = array_map(fn (array $e) => $e + ['discord_url' => null, 'event_url' => null],
            $calendar->eventsInRange($now, $until));

        $events = array_merge($guildEvents, $worldEvents);
        usort($events, fn ($a, $b) => $a['starts_at']->getTimestamp() <=> $b['starts_at']->getTimestamp());

        // Group by ISO week so the view can render "This week / Next week
        // / W19 / W20" headings without per-row date formatting noise.
        $byWeek = [];
        foreach ($events as $event) {
            $key = $event['starts_at']->format('o-W');
            $byWeek[$key]['events'][] = $event;
            $byWeek[$key]['week_start'] ??= $event['starts_at']->startOfWeek();
        }

        return view('dashboard.social', [
            'eventsByWeek' => $byWeek,
            'totalEvents' => count($events),
            'windowDays' => self::WINDOW_DAYS_AHEAD,
        ]);
    }
}
