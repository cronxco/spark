<div>
    @if ($chartData && $chartData->isNotEmpty())
        <x-card class="bg-base-200/50 border-2 border-primary/10">
            <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                <x-icon name="fas.chart-simple" class="w-5 h-5 text-primary" />
                Recent Trend: {{ $metricName }}
            </h3>

            <!-- Compact chart container -->
            <div
                wire:key="event-context-chart-{{ $chartData->first()['id'] ?? 'empty' }}"
                x-data="eventContextChart(@js($chartData), '{{ $valueUnit }}')"
                x-on:destroy="destroy()"
                class="h-[200px]"
            >
                <canvas x-ref="canvas"></canvas>
            </div>

            <div class="text-xs text-base-content/70 mt-3 text-center">
                Click chart to view other events • Showing ±14 days around this event
            </div>
        </x-card>
    @endif
</div>

@script
<script>
    Alpine.data('eventContextChart', (data, unit) => ({
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

            // Find current event index
            const currentIndex = data.findIndex(d => d.isCurrent);

            this.chart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.map(d => d.date),
                    datasets: [{
                        label: unit,
                        data: data.map(d => d.value),
                        borderColor: `rgb(${colors.primary})`,
                        borderWidth: 2,
                        tension: 0.4,
                        fill: false,
                        pointRadius: data.map((d, i) => d.isCurrent ? 6 : 4),
                        pointHoverRadius: data.map((d, i) => d.isCurrent ? 8 : 6),
                        pointBackgroundColor: data.map(d => d.isCurrent ? `rgb(${colors.accent})` : `rgb(${colors.primary})`),
                        pointBorderColor: data.map(d => d.isCurrent ? `rgb(${colors.accent})` : `rgb(${colors.primary})`),
                        pointBorderWidth: 2,
                    }]
                },
                options: {
                    ...window.chartUtils.getChartDefaults(),
                    onClick: (event, elements) => {
                        if (elements.length > 0) {
                            const index = elements[0].index;
                            const eventId = data[index].id;
                            window.Livewire.navigate(`/events/${eventId}`);
                        }
                    },
                    plugins: {
                        tooltip: {
                            backgroundColor: `rgba(${colors.primary}, 0.9)`,
                            callbacks: {
                                title: (items) => {
                                    const index = items[0].dataIndex;
                                    const item = data[index];
                                    return item.datetime + (item.isCurrent ? ' (This Event)' : '');
                                },
                                label: (context) => {
                                    return `${context.parsed.y.toFixed(2)} ${unit}`;
                                }
                            }
                        },
                        legend: {
                            display: false,
                        }
                    },
                    scales: {
                        y: {
                            ticks: {
                                callback: (value) => `${value.toFixed(0)}`,
                                color: `rgba(${colors.baseContent}, 0.7)`
                            },
                            grid: {
                                color: `rgba(${colors.baseContent}, 0.1)`
                            }
                        },
                        x: {
                            ticks: {
                                maxRotation: 45,
                                minRotation: 45,
                                maxTicksLimit: 10,
                                color: `rgba(${colors.baseContent}, 0.7)`
                            },
                            grid: {
                                color: `rgba(${colors.baseContent}, 0.1)`
                            }
                        }
                    },
                    interaction: {
                        mode: 'nearest',
                        axis: 'x',
                        intersect: false
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
