<div>
    <!-- Chart Controls -->
    <div class="flex flex-wrap items-center justify-between gap-4 mb-4">
        <div class="flex gap-2">
            <select wire:model.live="timeRange" class="select select-bordered select-sm">
                <option value="30">Last 30 Days</option>
                <option value="60">Last 60 Days</option>
                <option value="90">Last 90 Days</option>
                <option value="180">Last 180 Days</option>
                <option value="365">Last Year</option>
            </select>
        </div>

        <div class="flex flex-wrap gap-2">
            <label class="label cursor-pointer gap-2">
                <input type="checkbox" wire:model.live="showNormalRange" class="checkbox checkbox-sm" />
                <span class="label-text text-xs">Normal Range</span>
            </label>

            <label class="label cursor-pointer gap-2">
                <input type="checkbox" wire:model.live="showMovingAverage" class="checkbox checkbox-sm" />
                <span class="label-text text-xs">Moving Average</span>
            </label>
        </div>
    </div>

    @if ($chartData->isEmpty())
        <div class="text-center py-8">
            <x-icon name="o-chart-bar" class="w-16 h-16 mx-auto text-base-content/70 mb-4" />
            <h3 class="text-lg font-medium text-base-content mb-2">No data available</h3>
            <p class="text-base-content/70">No events found for the selected time range</p>
        </div>
    @else
        <!-- Chart -->
        <div
            wire:key="metric-chart-{{ $timeRange }}-{{ $showNormalRange ? '1' : '0' }}-{{ $showMovingAverage ? '1' : '0' }}"
            x-data="metricChart(
                @js($chartData),
                @js($movingAverage),
                {{ $normalLowerBound }},
                {{ $normalUpperBound }},
                {{ $meanValue }},
                {{ $showNormalRange ? 'true' : 'false' }},
                {{ $showMovingAverage ? 'true' : 'false' }},
                '{{ $metric->value_unit }}'
            )"
            x-on:destroy="destroy()"
            class="h-[350px]"
        >
            <canvas x-ref="canvas"></canvas>
        </div>
    @endif
</div>

@script
<script>
    Alpine.data('metricChart', (
        data,
        movingAvg,
        lowerBound,
        upperBound,
        mean,
        showNormal,
        showMA,
        unit
    ) => ({
        chart: null,

        init() {
            this.renderChart();
        },

        renderChart() {
            if (!data || data.length === 0) {
                return;
            }

            // Destroy existing chart if it exists
            if (this.chart) {
                this.chart.destroy();
                this.chart = null;
            }

            const ctx = this.$refs.canvas.getContext('2d');
            const colors = window.chartUtils.getThemeColors();

            // Build datasets
            const datasets = [{
                label: unit,
                data: data.map(d => d.value),
                borderColor: `rgb(${colors.primary})`,
                backgroundColor: `rgba(${colors.primary}, 0.1)`,
                tension: 0.1,
                fill: false,
                pointRadius: 3,
                pointHoverRadius: 5,
            }];

            // Add normal range bands if enabled
            if (showNormal) {
                datasets.push({
                    label: 'Upper Bound',
                    data: data.map(() => upperBound),
                    borderColor: `rgba(${colors.error}, 0.5)`,
                    borderDash: [5, 5],
                    pointRadius: 0,
                    fill: false,
                });

                datasets.push({
                    label: 'Lower Bound',
                    data: data.map(() => lowerBound),
                    borderColor: `rgba(${colors.info}, 0.5)`,
                    borderDash: [5, 5],
                    pointRadius: 0,
                    fill: false,
                });

                datasets.push({
                    label: 'Mean',
                    data: data.map(() => mean),
                    borderColor: `rgba(${colors.secondary}, 0.6)`,
                    borderDash: [2, 2],
                    pointRadius: 0,
                    fill: false,
                    borderWidth: 1,
                });
            }

            // Add moving average if enabled
            if (showMA && movingAvg && movingAvg.length > 0) {
                datasets.push({
                    label: '7-Day Moving Average',
                    data: movingAvg.map(d => d.value),
                    borderColor: `rgba(${colors.accent}, 0.8)`,
                    backgroundColor: 'transparent',
                    tension: 0.3,
                    fill: false,
                    pointRadius: 0,
                    borderWidth: 2,
                });
            }

            this.chart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.map(d => d.date),
                    datasets: datasets
                },
                options: {
                    ...window.chartUtils.getChartDefaults(),
                    plugins: {
                        legend: {
                            display: true,
                            position: 'bottom'
                        },
                        tooltip: {
                            backgroundColor: `rgba(${colors.primary}, 0.9)`,
                            callbacks: {
                                label: function (context) {
                                    let label = context.dataset.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    if (context.parsed.y !== null) {
                                        label += context.parsed.y.toFixed(2);
                                        if (context.datasetIndex === 0) {
                                            label += ' ' + unit;
                                        }
                                    }
                                    return label;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: false,
                            title: {
                                display: true,
                                text: unit
                            },
                            ticks: {
                                callback: (value) => value.toFixed(0)
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Date'
                            },
                            ticks: {
                                maxRotation: 45,
                                minRotation: 45
                            }
                        }
                    }
                }
            });
        },

        destroy() {
            if (this.chart) {
                this.chart.destroy();
                this.chart = null;
            }
        }
    }));
</script>
@endscript
