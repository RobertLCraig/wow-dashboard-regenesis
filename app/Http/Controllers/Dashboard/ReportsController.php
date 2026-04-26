<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\WclActorParse;
use App\Models\WclFight;
use App\Models\WclReport;
use Illuminate\View\View;

/**
 * Browse the WCL data we've ingested. Index shows the recent reports
 * with kill/wipe summary; detail page expands a single report into
 * its fights with per-actor parses.
 *
 * No write paths here - this is pure presentation over the data the
 * importer already wrote.
 */
class ReportsController extends Controller
{
    public function index(): View
    {
        abort_unless(auth()->user()?->can('reports.view'), 403);

        $reports = WclReport::query()
            ->orderByDesc('start_time')
            ->limit(40)
            ->withCount([
                'fights',
                'fights as kills_count' => fn ($q) => $q->where('kill', true),
            ])
            ->get();

        return view('dashboard.reports.index', [
            'reports' => $reports,
        ]);
    }

    public function show(string $code): View
    {
        abort_unless(auth()->user()?->can('reports.view'), 403);

        $report = WclReport::query()
            ->where('code', $code)
            ->firstOrFail();

        $fights = WclFight::query()
            ->where('wcl_report_id', $report->id)
            ->orderBy('fight_id')
            ->with(['parses' => fn ($q) => $q->orderByDesc('metric_per_second')])
            ->get();

        return view('dashboard.reports.show', [
            'report' => $report,
            'fights' => $fights,
        ]);
    }
}
