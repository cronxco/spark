<div>
    <x-header title="Metrics" subtitle="Track trends and anomalies across your data" separator>
        <x-slot:actions>
            <div class="flex flex-wrap gap-2 items-center">
                <button wire:click="calculateStatistics" class="btn btn-outline btn-sm">
                    <x-icon name="o-calculator" class="h-4 w-4" />
                    Calculate Statistics
                </button>

                <button wire:click="detectTrends" class="btn btn-outline btn-sm">
                    <x-icon name="o-chart-bar" class="h-4 w-4" />
                    Detect Trends
                </button>

                <div class="divider divider-horizontal mx-0"></div>

                <select wire:model.live="sortBy" class="select select-bordered select-sm w-40">
                    <option value="interesting">Most Interesting</option>
                    <option value="recent">Most Recent</option>
                    <option value="service">By Service</option>
                </select>

                @if ($sortBy === 'service')
                    <select wire:model.live="filterService" class="select select-bordered select-sm w-40">
                        <option value="">All Services</option>
                        @foreach ($services as $service)
                            <option value="{{ $service }}">{{ ucfirst($service) }}</option>
                        @endforeach
                    </select>
                @endif
            </div>
        </x-slot:actions>
    </x-header>

    {{-- Recent Trends Summary --}}
    @if ($recentTrends->count() > 0)
        <div class="alert alert-info">
            <x-icon name="o-information-circle" class="h-5 w-5" />
            <div>
                <h3 class="font-bold">{{ $recentTrends->count() }} Unacknowledged Trends</h3>
                <div class="text-sm">
                    You have {{ $recentTrends->where('type', 'like', 'anomaly_%')->count() }} anomalies and
                    {{ $recentTrends->where('type', 'not like', 'anomaly_%')->count() }} trends awaiting review.
                </div>
            </div>
        </div>
    @endif

    {{-- Metrics Grid --}}
    @if ($metrics->isEmpty())
        <div class="card bg-base-200 shadow">
            <div class="card-body">
                <div class="text-center py-12">
                    <x-icon name="o-chart-bar" class="w-16 h-16 mx-auto text-base-content/70 mb-4" />
                    <h3 class="text-lg font-medium text-base-content mb-2">No Metrics Available</h3>
                    <p class="text-base-content/70 mb-6">
                        Metrics require at least 30 days of event data. Keep using your integrations and check back soon!
                    </p>
                </div>
            </div>
        </div>
    @else
        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            @foreach ($metrics as $metric)
                <a href="{{ route('metrics.show', $metric->id) }}" wire:navigate
                    class="card bg-base-100 shadow transition-shadow hover:shadow-lg cursor-pointer">
                    <div class="card-body">
                        {{-- Header --}}
                        <div class="flex items-start justify-between">
                            <div class="flex-1">
                                <h3 class="card-title text-base">{{ $metric->getDisplayName() }}</h3>
                                <p class="text-xs text-gray-500">
                                    {{ ucfirst($metric->service) }} • {{ $metric->value_unit }}
                                </p>
                            </div>

                            {{-- Badges --}}
                            @if ($metric->unacknowledged_trends_count > 0)
                                <div class="badge badge-primary badge-sm">
                                    {{ $metric->unacknowledged_trends_count }}
                                </div>
                            @endif
                        </div>

                        {{-- Stats --}}
                        <div class="mt-4 grid grid-cols-3 gap-2 text-center">
                            <div>
                                <div class="text-xs text-gray-500">Current</div>
                                <div class="font-semibold">{!! format_event_value_display($metric->mean_value, $metric->value_unit, $metric->service, $metric->action) !!}</div>
                            </div>
                            <div>
                                <div class="text-xs text-gray-500">Min</div>
                                <div class="font-semibold">{!! format_event_value_display($metric->min_value, $metric->value_unit, $metric->service, $metric->action) !!}</div>
                            </div>
                            <div>
                                <div class="text-xs text-gray-500">Max</div>
                                <div class="font-semibold">{!! format_event_value_display($metric->max_value, $metric->value_unit, $metric->service, $metric->action) !!}</div>
                            </div>
                        </div>

                        {{-- Anomaly indicators --}}
                        @if ($metric->recent_anomalies_count > 0)
                            <div class="mt-2 flex items-center gap-2 rounded-lg bg-warning/10 px-3 py-2 text-sm">
                                <x-icon name="o-exclamation-triangle" class="h-4 w-4 text-warning" />
                                <span class="text-warning">{{ $metric->recent_anomalies_count }} recent
                                    {{ Str::plural('anomaly', $metric->recent_anomalies_count) }}</span>
                            </div>
                        @endif

                        {{-- Last updated --}}
                        <div class="mt-2 text-xs text-gray-500">
                            Last event: {{ $metric->last_event_at?->diffForHumans() }}
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    @endif

    {{-- Recent Trends List --}}
    @if ($recentTrends->count() > 0)
        <div class="card bg-base-200 shadow">
            <div class="card-body">
                <h3 class="card-title">Recent Trends & Anomalies</h3>

                <div class="divide-y">
                    @foreach ($recentTrends as $trend)
                        <div class="flex items-center justify-between py-3">
                            <div class="flex-1">
                                <div class="flex items-center gap-2">
                                    @if ($trend->getDirection() === 'up')
                                        <x-icon name="o-arrow-trending-up" class="h-5 w-5 text-success" />
                                    @elseif ($trend->getDirection() === 'down')
                                        <x-icon name="o-arrow-trending-down" class="h-5 w-5 text-error" />
                                    @else
                                        <x-icon name="o-minus" class="h-5 w-5 text-gray-500" />
                                    @endif

                                    <div>
                                        <div class="font-semibold">
                                            {{ $trend->metricStatistic->getDisplayName() }}
                                        </div>
                                        <div class="text-sm text-gray-500">
                                            {{ $trend->getTypeLabel() }} •
                                            {{ $trend->detected_at->diffForHumans() }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center gap-2">
                                <div class="text-right text-sm">
                                    <div class="font-semibold">
                                        {!! format_event_value_display($trend->current_value, $trend->metricStatistic->value_unit, $trend->metricStatistic->service, $trend->metricStatistic->action) !!}
                                    </div>
                                    <div class="text-gray-500">
                                        vs {!! format_event_value_display($trend->baseline_value, $trend->metricStatistic->value_unit, $trend->metricStatistic->service, $trend->metricStatistic->action) !!}
                                    </div>
                                </div>

                                <a href="{{ route('metrics.show', $trend->metricStatistic->id) }}"
                                    class="btn btn-ghost btn-sm">
                                    View
                                </a>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
