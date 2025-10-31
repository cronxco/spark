<div>
    @if ($chartData->isEmpty())
        <div class="text-center py-8">
            <x-icon name="o-chart-bar" class="w-16 h-16 mx-auto text-base-content/70 mb-4" />
            <h3 class="text-lg font-medium text-base-content mb-2">No balance history</h3>
            <p class="text-base-content/70">Balance data will appear here once recorded</p>
        </div>
    @else
        <!-- Range selector -->
        <div class="flex gap-2 mb-4 justify-end">
            <div class="btn-group">
                <button wire:click="$set('rangeMonths', 3)" class="btn btn-sm {{ $rangeMonths === 3 ? 'btn-active' : '' }}">
                    3M
                </button>
                <button wire:click="$set('rangeMonths', 6)" class="btn btn-sm {{ $rangeMonths === 6 ? 'btn-active' : '' }}">
                    6M
                </button>
                <button wire:click="$set('rangeMonths', 12)" class="btn btn-sm {{ $rangeMonths === 12 ? 'btn-active' : '' }}">
                    12M
                </button>
                <button wire:click="$set('rangeMonths', 0)" class="btn btn-sm {{ $rangeMonths === 0 ? 'btn-active' : '' }}">
                    All
                </button>
            </div>
        </div>

        <!-- Chart container -->
        <div
            wire:key="balance-chart-{{ $rangeMonths }}"
            x-data="balanceChart(@js($chartData), '{{ $currencySymbol }}')"
            x-on:destroy="destroy()"
            class="h-[300px]"
        >
            <canvas x-ref="canvas"></canvas>
        </div>
    @endif
</div>

@script
<script>
    Alpine.data('balanceChart', (data, symbol) => ({
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

            // Check if any balances are negative
            const hasNegative = data.some(d => d.balance < 0);

            this.chart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.map(d => d.date),
                    datasets: [{
                        label: 'Balance',
                        data: data.map(d => d.balance),
                        borderColor: `rgb(${colors.primary})`,
                        backgroundColor: `rgba(${colors.primary}, 0.1)`,
                        tension: 0.1,
                        fill: true,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        pointBackgroundColor: data.map(d => d.balance < 0 ? `rgb(${colors.error})` : `rgb(${colors.primary})`),
                        pointBorderColor: data.map(d => d.balance < 0 ? `rgb(${colors.error})` : `rgb(${colors.primary})`),
                        segment: {
                            borderColor: ctx => {
                                // Color line segments red if going through negative
                                const prevBalance = data[ctx.p0DataIndex]?.balance;
                                const currBalance = data[ctx.p1DataIndex]?.balance;
                                if (prevBalance < 0 || currBalance < 0) {
                                    return `rgb(${colors.error})`;
                                }
                                return `rgb(${colors.primary})`;
                            }
                        }
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
                                    return data[index].datetime;
                                },
                                label: (context) => {
                                    const value = context.parsed.y;
                                    if (value < 0) {
                                        return `Balance: -${symbol}${Math.abs(value).toFixed(2)}`;
                                    }
                                    return `Balance: ${symbol}${value.toFixed(2)}`;
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
                                callback: (value) => {
                                    if (value < 0) {
                                        return `-${symbol}${Math.abs(value).toFixed(0)}`;
                                    }
                                    return `${symbol}${value.toFixed(0)}`;
                                },
                                color: (context) => {
                                    // Color negative values in red
                                    return context.tick.value < 0 ? `rgb(${colors.error})` : undefined;
                                }
                            },
                            grid: {
                                color: (context) => {
                                    // Highlight the zero line
                                    if (context.tick.value === 0 && hasNegative) {
                                        return `rgba(${colors.baseContent}, 0.3)`;
                                    }
                                    return `rgba(${colors.baseContent}, 0.1)`;
                                },
                                lineWidth: (context) => {
                                    if (context.tick.value === 0 && hasNegative) {
                                        return 2;
                                    }
                                    return 1;
                                }
                            }
                        },
                        x: {
                            ticks: {
                                maxRotation: 45,
                                minRotation: 45
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
