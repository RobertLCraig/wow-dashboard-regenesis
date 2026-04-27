<?php

/**
 * General-dashboard widget catalogue. The view iterates this list
 * (after running it through WidgetOrderResolver to apply the user's
 * saved order) so adding/removing/reordering a widget is a config
 * change rather than a Blade edit.
 *
 * Each widget declares:
 *   - key:        stable identifier (used in users.dashboard_layout)
 *   - title:      human-readable label for the drag handle
 *   - partial:    Blade include path
 *   - data_key:   name of the controller-supplied variable
 *   - col_span:   Tailwind class string for the wrapping <div>
 *
 * Order in this array is the default; users override via the
 * dashboard layout editor.
 */
return [

    'widgets' => [
        [
            'key'      => 'action-queue',
            'title'    => 'Action queue',
            'partial'  => 'dashboard.widgets.action-queue',
            'data_key' => 'actionQueue',
            'col_span' => 'col-span-full xl:col-span-2',
        ],
        [
            'key'      => 'upcoming-events',
            'title'    => 'Upcoming events',
            'partial'  => 'dashboard.widgets.upcoming-events',
            'data_key' => 'upcomingEvents',
            'col_span' => 'col-span-full md:col-span-2 xl:col-span-1',
        ],
        [
            'key'      => 'roster-health',
            'title'    => 'Roster health (KPIs)',
            'partial'  => 'dashboard.widgets.roster-health',
            'data_key' => 'health',
            'col_span' => 'col-span-full',
        ],
        // recently-inactive + alt-groups widgets were retired here once
        // the /roster page (with its inactive_30d filter and ?group=1
        // toggle) shipped: same data, sharper UX, single home. The
        // widget Blade files live on under resources/views/dashboard/
        // widgets/ but nothing references them. WidgetOrderResolver
        // silently drops their old keys from any saved layout.
        [
            'key'      => 'anniversaries',
            'title'    => 'Anniversaries',
            'partial'  => 'dashboard.widgets.anniversaries',
            'data_key' => 'anniversaries',
            'col_span' => '',
        ],
        [
            'key'      => 'team-progression',
            'title'    => 'Team progression',
            'partial'  => 'dashboard.widgets.team-progression',
            'data_key' => 'teamProgression',
            'col_span' => 'col-span-full xl:col-span-2',
        ],
        [
            'key'      => 'rank-distribution',
            'title'    => 'Rank distribution',
            'partial'  => 'dashboard.widgets.rank-distribution',
            'data_key' => 'rankDistribution',
            'col_span' => '',
        ],
        [
            'key'      => 'log-timeline',
            'title'    => 'Recent activity',
            'partial'  => 'dashboard.widgets.log-timeline',
            'data_key' => 'timeline',
            'col_span' => 'col-span-full',
        ],
        [
            'key'      => 'bans',
            'title'    => 'Ban list',
            'partial'  => 'dashboard.widgets.bans',
            'data_key' => 'bans',
            'col_span' => '',
        ],
        [
            'key'      => 'churn',
            'title'    => 'Churn',
            'partial'  => 'dashboard.widgets.churn',
            'data_key' => 'churn',
            'col_span' => 'col-span-full xl:col-span-2',
        ],
    ],

];
