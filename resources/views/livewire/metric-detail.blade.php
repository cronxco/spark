<div>
    <x-header separator>
        <x-slot:title>
            {{ $metric->getDisplayName() }}
        </x-slot:title>
        <x-slot:subtitle>
            <x-button link="{{ route('metrics.index') }}" wire:navigate class="btn-ghost btn-xs">
                <x-icon name="fas-arrow-left" class="w-4 h-4" />
                Back to Metrics
            </x-button>
        </x-slot:subtitle>
        <x-slot:actions>
            <button wire:click="toggleTracking" class="btn btn-outline btn-sm">
                @if ($isTrackingDisabled)
                    <x-icon name="fas-play" class="h-4 w-4" />
                    Enable Tracking
                @else
                    <x-icon name="fas-pause" class="h-4 w-4" />
                    Disable Tracking
                @endif
            </button>
        </x-slot:actions>
    </x-header>

    @if ($isTrackingDisabled)
        <div class="alert alert-warning">
            <x-icon name="fas-triangle-exclamation" class="h-5 w-5" />
            <div>
                <h3 class="font-bold">Tracking Disabled</h3>
                <div class="text-sm">
                    This metric is currently not being tracked. Enable tracking to resume anomaly and trend detection.
                </div>
            </div>
        </div>
    @endif

    {{-- Primary Hero Card with Statistics --}}
    <x-card>
        <div class="flex flex-col sm:flex-row items-start gap-4 lg:gap-6">
            {{-- Large icon --}}
            <div class="flex-shrink-0 self-center sm:self-start">
                <div class="w-12 h-12 sm:w-16 sm:h-16 rounded-full bg-primary/10 flex items-center justify-center">
                    <x-icon name="fas-chart-simple" class="w-6 h-6 sm:w-8 sm:h-8 text-primary" />
                </div>
            </div>

            {{-- Main content --}}
            <div class="flex-1 w-full">
                <div class="mb-4 text-center sm:text-left">
                    <h2 class="text-xl sm:text-2xl lg:text-3xl font-bold text-base-content mb-2">
                        {{ $metric->getDisplayName() }}
                    </h2>
                    <div class="text-sm text-base-content/70">
                        {{ ucfirst($metric->service) }} · {{ $metric->value_unit }}
                    </div>
                </div>

                {{-- Statistics Grid --}}
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 p-4 lg:p-6 rounded-lg bg-base-300/50 border border-base-300">
                    <div class="text-center sm:text-left">
                        <div class="text-xs text-base-content/70 mb-1">Mean</div>
                        <div class="text-2xl font-bold">{{ number_format($metric->mean_value, 2) }}</div>
                        <div class="text-xs text-base-content/70">{{ $metric->value_unit }}</div>
                    </div>

                    <div class="text-center sm:text-left">
                        <div class="text-xs text-base-content/70 mb-1">Std Dev</div>
                        <div class="text-2xl font-bold">{{ number_format($metric->stddev_value, 2) }}</div>
                        <div class="text-xs text-base-content/70">±{{ $metric->value_unit }}</div>
                    </div>

                    <div class="text-center sm:text-left">
                        <div class="text-xs text-base-content/70 mb-1">Range</div>
                        <div class="text-xl font-bold">
                            {{ number_format($metric->min_value, 1) }} - {{ number_format($metric->max_value, 1) }}
                        </div>
                        <div class="text-xs text-base-content/70">{{ $metric->value_unit }}</div>
                    </div>

                    <div class="text-center sm:text-left">
                        <div class="text-xs text-base-content/70 mb-1">Events</div>
                        <div class="text-2xl font-bold">{{ number_format($metric->event_count) }}</div>
                        <div class="text-xs text-base-content/70">total data points</div>
                    </div>
                </div>
            </div>
        </div>
    </x-card>

    {{-- Chart Controls --}}
    <div class="card bg-base-200 shadow">
        <div class="card-body">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex gap-2">
                    <select wire:model.live="timeRange" class="select select-bordered select-sm">
                        <option value="30">Last 30 Days</option>
                        <option value="60">Last 60 Days</option>
                        <option value="90">Last 90 Days</option>
                        <option value="365">Last Year</option>
                    </select>
                </div>

                <div class="flex flex-wrap gap-2">
                    <label class="label cursor-pointer gap-2">
                        <input type="checkbox" wire:model.live="showNormalRange" class="checkbox checkbox-sm" />
                        <span class="label-text text-xs">Normal Range</span>
                    </label>

                    <label class="label cursor-pointer gap-2">
                        <input type="checkbox" wire:model.live="showAnomalies" class="checkbox checkbox-sm" />
                        <span class="label-text text-xs">Anomalies</span>
                    </label>

                    <label class="label cursor-pointer gap-2">
                        <input type="checkbox" wire:model.live="showMovingAverage" class="checkbox checkbox-sm" />
                        <span class="label-text text-xs">Moving Average</span>
                    </label>
                </div>
            </div>

            {{-- Chart --}}
            <div class="mt-4">
                <canvas id="metricChart" height="100" data-labels='{{ json_encode($chartLabels) }}' data-data='{{ json_encode($chartData) }}' data-mean='{{ $metric->mean_value }}' data-lower='{{ $metric->normal_lower_bound }}' data-upper='{{ $metric->normal_upper_bound }}' data-show-normal='{{ $showNormalRange ? 1 : 0 }}'></canvas>
            </div>
        </div>
    </div>

    {{-- Anomalies --}}
    @if ($anomalies->count() > 0)
        <div class="card bg-base-200 shadow">
            <div class="card-body">
                <h3 class="card-title">Detected Anomalies</h3>

                <div class="divide-y">
                    @foreach ($anomalies as $anomaly)
                        <div class="flex items-center justify-between py-3">
                            <div class="flex items-center gap-3">
                                @if ($anomaly->type === 'anomaly_high')
                                    <div class="badge badge-error">High</div>
                                @else
                                    <div class="badge badge-info">Low</div>
                                @endif

                                <div>
                                    <div class="font-semibold">
                                        {{ number_format($anomaly->current_value, 2) }} {{ $metric->value_unit }}
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        {{ $anomaly->detected_at->format('M j, Y') }} •
                                        {{ number_format($anomaly->deviation, 1) }}σ from mean
                                    </div>
                                </div>
                            </div>

                            @if (!$anomaly->acknowledged_at)
                                <button wire:click="acknowledgeTrend('{{ $anomaly->id }}')" class="btn btn-ghost btn-sm">
                                    Acknowledge
                                </button>
                            @else
                                <span class="text-xs text-gray-500">
                                    Acknowledged {{ $anomaly->acknowledged_at->diffForHumans() }}
                                </span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    {{-- Trends --}}
    @if ($detectedTrends->count() > 0)
        <div class="card bg-base-200 shadow">
            <div class="card-body">
                <h3 class="card-title">Detected Trends</h3>

                <div class="divide-y">
                    @foreach ($detectedTrends as $trend)
                        <div class="flex items-center justify-between py-3">
                            <div class="flex items-center gap-3">
                                @if ($trend->getDirection() === 'up')
                                    <x-icon name="fas-arrow-trend-up" class="h-6 w-6 text-success" />
                                @else
                                    <x-icon name="fas-arrow-trend-down" class="h-6 w-6 text-error" />
                                @endif

                                <div>
                                    <div class="font-semibold">{{ $trend->getTypeLabel() }}</div>
                                    <div class="text-sm text-gray-500">
                                        {{ $trend->detected_at->format('M j, Y') }} •
                                        {{ number_format(abs(($trend->current_value - $trend->baseline_value) / $trend->baseline_value) * 100, 1) }}%
                                        change
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        {{ number_format($trend->baseline_value, 1) }} →
                                        {{ number_format($trend->current_value, 1) }} {{ $metric->value_unit }}
                                    </div>
                                </div>
                            </div>

                            @if (!$trend->acknowledged_at)
                                <button wire:click="acknowledgeTrend('{{ $trend->id }}')" class="btn btn-ghost btn-sm">
                                    Acknowledge
                                </button>
                            @else
                                <span class="text-xs text-gray-500">
                                    Acknowledged {{ $trend->acknowledged_at->diffForHumans() }}
                                </span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    @if ($anomalies->isEmpty() && $detectedTrends->isEmpty())
        <div class="card bg-base-200 shadow">
            <div class="card-body">
                <div class="text-center py-12">
                    <x-icon name="fas-circle-check" class="w-16 h-16 mx-auto text-base-content/70 mb-4" />
                    <h3 class="text-lg font-medium text-base-content mb-2">No Trends or Anomalies Detected</h3>
                    <p class="text-base-content/70">
                        Your {{ strtolower($metric->getDisplayName()) }} is stable with no significant deviations.
                    </p>
                </div>
            </div>
        </div>
    @endif

    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
        <script>
            document.addEventListener('livewire:init', () => {
                let chart = null;

                function renderChart() {
                    const ctx = document.getElementById('metricChart');
                    if (!ctx) return;

                    const labels = JSON.parse(ctx.dataset.labels || '[]');
                    const data = JSON.parse(ctx.dataset.data || '[]');
                    const mean = parseFloat(ctx.dataset.mean || '0');
                    const lowerBound = parseFloat(ctx.dataset.lower || '0');
                    const upperBound = parseFloat(ctx.dataset.upper || '0');

                    // Destroy existing chart
                    if (chart) {
                        chart.destroy();
                    }

                    // Prepare datasets
                    const datasets = [{
                        label: '{{ $metric->getDisplayName() }}',
                        data: data,
                        borderColor: 'rgb(75, 192, 192)',
                        backgroundColor: 'rgba(75, 192, 192, 0.1)',
                        tension: 0.1,
                        fill: false
                    }];

                    // Optionally include normal range lines if enabled
                    if (parseInt(ctx.dataset.showNormal || '0', 10) === 1) {
                        datasets.push({
                            label: 'Normal Upper Bound',
                            data: labels.map(() => upperBound),
                            borderColor: 'rgba(255, 99, 132, 0.5)',
                            borderDash: [5, 5],
                            pointRadius: 0,
                            fill: false
                        });

                        datasets.push({
                            label: 'Normal Lower Bound',
                            data: labels.map(() => lowerBound),
                            borderColor: 'rgba(54, 162, 235, 0.5)',
                            borderDash: [5, 5],
                            pointRadius: 0,
                            fill: false
                        });
                    }

                    chart = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: labels,
                            datasets: datasets
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: {
                                mode: 'index',
                                intersect: false,
                            },
                            plugins: {
                                legend: {
                                    display: true,
                                    position: 'bottom'
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            return context.dataset.label + ': ' + context.parsed.y.toFixed(2) +
                                                ' {{ $metric->value_unit }}';
                                        }
                                    }
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: false,
                                    title: {
                                        display: true,
                                        text: '{{ $metric->value_unit }}'
                                    }
                                },
                                x: {
                                    title: {
                                        display: true,
                                        text: 'Date'
                                    }
                                }
                            }
                        }
                    });
                }

                renderChart();

                // Re-render chart when Livewire updates
                Livewire.hook('morph.updated', () => {
                    setTimeout(renderChart, 100);
                });
            });
        </script>
    @endpush
</div>
