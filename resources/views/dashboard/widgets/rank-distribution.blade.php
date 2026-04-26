<section class="bg-panel border border-line rounded-lg overflow-hidden">
    <div x-data="{ explain: false }">
        <header class="px-4 py-3 border-b border-line flex items-center justify-between">
            <h2 class="text-sm font-semibold uppercase tracking-wider flex items-center gap-2">
                <span>Rank distribution</span>
                <x-explainer-toggle />
            </h2>
            <span class="text-xs text-muted">{{ collect($rankDistribution)->sum('count') }} active</span>
        </header>
        <x-explainer-panel title="Rank distribution">
            Active members grouped by their current guild rank. Useful for spotting rank
            inflation (too many officers, no recruits flowing through), for
            sanity-checking promotions before they happen, and for confirming the shape
            of the guild matches what the GM thinks it is. Doesn't include inactive
            members.
        </x-explainer-panel>
    </div>
    <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-4 items-center clarity-keep-grid">
        <div>
            <canvas id="rank-chart" width="220" height="220"></canvas>
        </div>
        <ul class="text-sm space-y-1">
            @foreach ($rankDistribution as $i => $row)
                <li class="flex items-center justify-between gap-3">
                    <span class="flex items-center gap-2">
                        <span class="inline-block w-2 h-2 rounded-full" style="background: {{ ['#5865F2','#a78bfa','#f472b6','#fb923c','#facc15','#34d399','#22d3ee','#94a3b8'][$i % 8] }}"></span>
                        <span>{{ $row['rank'] }}</span>
                    </span>
                    <span class="text-muted">{{ $row['count'] }}</span>
                </li>
            @endforeach
        </ul>
    </div>
    <script>
        (function () {
            const data = @json($rankDistribution);
            const ctx = document.getElementById('rank-chart');
            if (!ctx || !window.Chart) return;
            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: data.map(r => r.rank),
                    datasets: [{
                        data: data.map(r => r.count),
                        backgroundColor: ['#5865F2','#a78bfa','#f472b6','#fb923c','#facc15','#34d399','#22d3ee','#94a3b8'],
                        borderColor: '#15151f',
                        borderWidth: 2,
                    }],
                },
                options: {
                    plugins: { legend: { display: false } },
                    cutout: '60%',
                    responsive: false,
                },
            });
        })();
    </script>
</section>
