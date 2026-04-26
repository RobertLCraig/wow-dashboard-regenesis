<section class="bg-panel border border-line rounded-lg overflow-hidden">
    <div x-data="{ explain: false }">
        <header class="px-4 py-3 border-b border-line flex items-center justify-between">
            <h2 class="text-sm font-semibold uppercase tracking-wider flex items-center gap-2">
                <span>Churn (last 12 weeks)</span>
                <x-explainer-toggle />
            </h2>
            <span class="text-xs text-muted">
                {{ array_sum($churn['joiners']) }} in / {{ array_sum($churn['leavers']) }} out
            </span>
        </header>
        <x-explainer-panel title="Churn (last 12 weeks)">
            Weekly join vs. leave counts over the last 12 weeks, derived from
            JOINED / LEFT / KICKED log events. The story to watch is the gap between
            the two lines. A persistent gap of more leavers than joiners is the early
            warning signal that the guild is shrinking, even when the absolute headcount
            still looks fine. Big spikes are usually patch days, content droughts, or
            drama.
        </x-explainer-panel>
    </div>
    <div class="p-4">
        <canvas id="churn-chart" height="200"></canvas>
    </div>
    <script>
        (function () {
            const labels = @json($churn['labels']);
            const joiners = @json($churn['joiners']);
            const leavers = @json($churn['leavers']);
            const ctx = document.getElementById('churn-chart');
            if (!ctx || !window.Chart) return;
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels,
                    datasets: [
                        { label: 'Joiners', data: joiners, borderColor: '#34d399', backgroundColor: 'rgba(52,211,153,0.15)', tension: 0.3, fill: true },
                        { label: 'Leavers', data: leavers, borderColor: '#fb7185', backgroundColor: 'rgba(251,113,133,0.15)', tension: 0.3, fill: true },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: { ticks: { color: '#7a7a8c' }, grid: { color: '#252533' } },
                        y: { ticks: { color: '#7a7a8c', precision: 0 }, grid: { color: '#252533' }, beginAtZero: true },
                    },
                    plugins: {
                        legend: { labels: { color: '#e6e6f0' } },
                    },
                },
            });
        })();
    </script>
</section>
