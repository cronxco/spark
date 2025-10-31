<div>
    <x-header separator>
        <x-slot:title>
            {{ $metric->getDisplayName() }}
        </x-slot:title>
        <x-slot:subtitle>
            <x-button link="{{ route('metrics.index') }}" wire:navigate class="btn-ghost btn-xs">
                <x-icon name="o-arrow-left" class="w-4 h-4" />
                Back to Metrics
            </x-button>
        </x-slot:subtitle>
        <x-slot:actions>
            <button wire:click="toggleTracking" class="btn btn-outline btn-sm">
                @if ($isTrackingDisabled)
                    <x-icon name="o-play" class="h-4 w-4" />
                    Enable Tracking
                @else
                    <x-icon name="o-pause" class="h-4 w-4" />
                    Disable Tracking
                @endif
            </button>
        </x-slot:actions>
    </x-header>

    @if ($isTrackingDisabled)
        <div class="alert alert-warning">
            <x-icon name="o-exclamation-triangle" class="h-5 w-5" />
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
                    <x-icon name="o-chart-bar" class="w-6 h-6 sm:w-8 sm:h-8 text-primary" />
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
                        <div class="text-2xl font-bold">{!! format_event_value_display($metric->mean_value, $metric->value_unit, $metric->service, $metric->action) !!}</div>
                    </div>

                    <div class="text-center sm:text-left">
                        <div class="text-xs text-base-content/70 mb-1">Std Dev</div>
                        <div class="text-2xl font-bold">±{!! format_event_value_display($metric->stddev_value, $metric->value_unit, $metric->service, $metric->action) !!}</div>
                    </div>

                    <div class="text-center sm:text-left">
                        <div class="text-xs text-base-content/70 mb-1">Range</div>
                        <div class="text-xl font-bold">
                            {!! format_event_value_display($metric->min_value, $metric->value_unit, $metric->service, $metric->action) !!} - {!! format_event_value_display($metric->max_value, $metric->value_unit, $metric->service, $metric->action) !!}
                        </div>
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

    {{-- Chart --}}
    <div class="card bg-base-200 shadow">
        <div class="card-body">
            <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                <x-icon name="o-chart-bar" class="w-5 h-5 text-primary" />
                Metric Trend
            </h3>

            <div class="min-h-[350px]">
                <livewire:charts.metric-chart :metric="$metric" wire:lazy :key="'metric-chart-' . $metric->id" />
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
                                        {!! format_event_value_display($anomaly->current_value, $metric->value_unit, $metric->service, $metric->action) !!}
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
                                    <x-icon name="o-arrow-trending-up" class="h-6 w-6 text-success" />
                                @else
                                    <x-icon name="o-arrow-trending-down" class="h-6 w-6 text-error" />
                                @endif

                                <div>
                                    <div class="font-semibold">{{ $trend->getTypeLabel() }}</div>
                                    <div class="text-sm text-gray-500">
                                        {{ $trend->detected_at->format('M j, Y') }} •
                                        {{ number_format(abs(($trend->current_value - $trend->baseline_value) / $trend->baseline_value) * 100, 1) }}%
                                        change
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        {!! format_event_value_display($trend->baseline_value, $metric->value_unit, $metric->service, $metric->action) !!} →
                                        {!! format_event_value_display($trend->current_value, $metric->value_unit, $metric->service, $metric->action) !!}
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
                    <x-icon name="o-check-circle" class="w-16 h-16 mx-auto text-base-content/70 mb-4" />
                    <h3 class="text-lg font-medium text-base-content mb-2">No Trends or Anomalies Detected</h3>
                    <p class="text-base-content/70">
                        Your {{ strtolower($metric->getDisplayName()) }} is stable with no significant deviations.
                    </p>
                </div>
            </div>
        </div>
    @endif

</div>
