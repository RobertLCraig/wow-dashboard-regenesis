@extends('layouts.dashboard')

@section('title', 'General')

@section('content')
    <h1 class="text-xl font-semibold mb-6">General Guild Management</h1>

    {{-- Single responsive grid for the whole dashboard. The widget
         catalogue lives in config/dashboard.php; per-user layout
         override comes from users.dashboard_layout (resolved by
         App\Services\Dashboard\WidgetOrderResolver in the
         controller). DOM order is the user's preferred order, which
         doubles as the High-clarity-mode scroll order. --}}
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
        @foreach ($widgets as $widget)
            <div class="{{ $widget['col_span'] ?: '' }}" data-widget-key="{{ $widget['key'] }}">
                @include($widget['partial'], [$widget['data_key'] => $widgetData[$widget['data_key']] ?? null])
            </div>
        @endforeach
    </div>

    @if (! $lastSnapshot)
        <div class="mt-8 p-4 rounded border border-line bg-panel text-sm text-muted">
            No data ingested yet. Run <code class="text-ink">tools/grm-sync/grm-sync.ps1 -Force</code>
            on your PC after the next time you log in to WoW.
        </div>
    @endif
@endsection
