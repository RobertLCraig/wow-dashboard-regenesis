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

    /**
     * Bars stacked in one week row before the rest collapse into a
     * "+n more" note. Fixing it keeps a week's height predictable
     * however many world events happen to overlap.
     */
    private const GRID_LANES = 3;

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

        // Grid-view data: one entry per calendar week from the start of
        // this week through the week containing $until.
        $weeks = $view === 'grid' ? $this->gridWeeks($events, $now, $until) : [];

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
            'weeks' => $weeks,
            'totalEvents' => count($events),
            'windowDays' => self::WINDOW_DAYS_AHEAD,
            'announcements' => $announcements,
            'announcementWindowDays' => $announcementWindow,
            'subscribeUrl' => $subscribeUrl,
            'quickCreatePreset' => $quickCreatePreset,
        ]);
    }

    /**
     * Turn the merged event list into week rows the month grid can draw
     * as continuous bars. An event that covers several days becomes ONE
     * bar per week it touches - clipped to that week's Monday..Sunday -
     * instead of a chip repeated in every day cell it overlaps. A bar
     * that runs off either end of the week is flagged so the view can
     * square that edge off and mark it as continuing.
     *
     * Bars are packed into a fixed number of lanes, longest first, so
     * two events that overlap in time sit on different rows and a week's
     * height depends only on how many lanes are occupied. Anything that
     * will not fit in a lane is counted per day as "+n more" rather than
     * silently dropped.
     *
     * @param  list<array{name:string, starts_at:CarbonImmutable, ends_at:?CarbonImmutable, tone:string}>  $events
     * @return list<array{days:list<array{date:CarbonImmutable, is_today:bool, in_window:bool, overflow:int}>, bars:list<array{name:string, tone:string, title:string, col:int, span:int, lane:int, continues_before:bool, continues_after:bool}>, lanes:int, has_overflow:bool}>
     */
    private function gridWeeks(array $events, CarbonImmutable $now, CarbonImmutable $until): array
    {
        $today = $now->startOfDay();
        $weeks = [];

        for ($weekStart = $today->startOfWeek(); $weekStart->lessThanOrEqualTo($until); $weekStart = $weekStart->addWeek()) {
            $weekEnd = $weekStart->addDays(6);

            $days = [];
            for ($i = 0; $i < 7; $i++) {
                $date = $weekStart->addDays($i);
                $days[] = [
                    'date' => $date,
                    'is_today' => $date->isSameDay($now),
                    'in_window' => $date->greaterThanOrEqualTo($today) && $date->lessThanOrEqualTo($until),
                    'overflow' => 0,
                ];
            }

            $segments = [];
            foreach ($events as $event) {
                $start = $event['starts_at']->startOfDay();
                $end = ($event['ends_at'] ?? $event['starts_at'])->startOfDay();
                if ($end->lessThan($weekStart) || $start->greaterThan($weekEnd)) {
                    continue;
                }

                $continuesBefore = $start->lessThan($weekStart);
                $continuesAfter = $end->greaterThan($weekEnd);
                $firstCol = $continuesBefore ? 1 : (int) $weekStart->diffInDays($start, false) + 1;
                $lastCol = $continuesAfter ? 7 : (int) $weekStart->diffInDays($end, false) + 1;

                $segments[] = [
                    'name' => $event['name'],
                    'tone' => $event['tone'],
                    'title' => $event['name'].' - '.($start->equalTo($end)
                        ? $event['starts_at']->format('D j M H:i')
                        : $start->format('D j M').' to '.$end->format('D j M')),
                    'col' => $firstCol,
                    'span' => $lastCol - $firstCol + 1,
                    'continues_before' => $continuesBefore,
                    'continues_after' => $continuesAfter,
                ];
            }

            // Longest bars claim the top lanes, so a long world event
            // keeps the same lane from one week row to the next.
            usort($segments, fn (array $a, array $b) => [$b['span'], $a['col'], $a['name']] <=> [$a['span'], $b['col'], $b['name']]);

            $occupied = [];
            $bars = [];
            foreach ($segments as $segment) {
                $from = $segment['col'];
                $to = $from + $segment['span'] - 1;

                $lane = null;
                for ($i = 0; $i < self::GRID_LANES; $i++) {
                    foreach ($occupied[$i] ?? [] as [$a, $b]) {
                        if ($from <= $b && $to >= $a) {
                            continue 2;
                        }
                    }
                    $lane = $i;
                    break;
                }

                if ($lane === null) {
                    for ($c = $from; $c <= $to; $c++) {
                        $days[$c - 1]['overflow']++;
                    }

                    continue;
                }

                $occupied[$lane][] = [$from, $to];
                $bars[] = $segment + ['lane' => $lane];
            }

            $weeks[] = [
                'days' => $days,
                'bars' => $bars,
                'lanes' => $occupied === [] ? 0 : max(array_keys($occupied)) + 1,
                'has_overflow' => array_sum(array_column($days, 'overflow')) > 0,
            ];
        }

        return $weeks;
    }
}
