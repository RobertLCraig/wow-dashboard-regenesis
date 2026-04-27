<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\DiscordAnnouncement;
use App\Models\RaidEvent;
use App\Services\Teams\TeamScheduleResolver;
use App\Services\WorldEvents\WorldEventsCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
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

    public function index(Request $request, WorldEventsCalendar $calendar): View
    {
        abort_unless(auth()->user()?->can('dashboard.social.view'), 403);

        $view = $request->query('view') === 'grid' ? 'grid' : 'list';
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

        // Grid-view data: flat list of days from start of this week
        // through end of the week containing $until. For each day we
        // collect events that overlap it (multi-day events appear in
        // every day they cover).
        $days = [];
        if ($view === 'grid') {
            $gridStart = $now->startOfWeek();
            $gridEnd = $until->endOfWeek();
            $cursor = $gridStart;
            while ($cursor->lessThanOrEqualTo($gridEnd)) {
                $dayStart = $cursor->startOfDay();
                $dayEnd = $cursor->endOfDay();
                $dayEvents = array_values(array_filter($events, function (array $e) use ($dayStart, $dayEnd): bool {
                    $startsAfterDayEnd = $e['starts_at']->greaterThan($dayEnd);
                    $effectiveEnd = $e['ends_at'] ?? $e['starts_at'];
                    $endsBeforeDayStart = $effectiveEnd->lessThan($dayStart);
                    return ! $startsAfterDayEnd && ! $endsBeforeDayStart;
                }));
                $days[] = [
                    'date' => $cursor,
                    'events' => $dayEvents,
                    'is_today' => $cursor->isSameDay($now),
                    'in_window' => $cursor->greaterThanOrEqualTo($now->startOfDay()) && $cursor->lessThanOrEqualTo($until),
                ];
                $cursor = $cursor->addDay();
            }
        }

        $announcementWindow = (int) config('discord.announcements_window_days', 30);
        $announcements = DiscordAnnouncement::query()
            ->where('posted_at', '>=', $now->subDays($announcementWindow))
            ->orderByDesc('posted_at')
            ->limit(10)
            ->get();

        // Per-user calendar token drives the .ics subscribe link. The
        // user model lazy-creates a stable random token on first access;
        // rotation is a follow-up settings-page concern.
        $user = auth()->user();
        $subscribeUrl = $user && $user->calendar_token
            ? route('calendar.social.subscription', ['token' => $user->calendar_token])
            : null;

        // Quick-create panel preset matches the team-dashboard pattern:
        // posts to /events with channel + template + leader pre-filled
        // from config('raidhelper.teams.social'). Only show the panel
        // to users who can actually create events.
        $quickCreatePreset = $user && $user->can('events.create')
            ? TeamScheduleResolver::for('social')
            : null;

        return view('dashboard.social', [
            'view' => $view,
            'eventsByWeek' => $byWeek,
            'days' => $days,
            'totalEvents' => count($events),
            'windowDays' => self::WINDOW_DAYS_AHEAD,
            'announcements' => $announcements,
            'announcementWindowDays' => $announcementWindow,
            'subscribeUrl' => $subscribeUrl,
            'quickCreatePreset' => $quickCreatePreset,
        ]);
    }
}
