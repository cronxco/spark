<div class="space-y-4 lg:space-y-6">
    {{-- Loading State --}}
    <div wire:loading wire:target="loadMemoryData" class="card bg-base-200 shadow">
        <div class="card-body text-center py-12">
            <div class="loading loading-spinner loading-lg mx-auto mb-4"></div>
            <p class="text-sm text-base-content/60">Loading memory data...</p>
        </div>
    </div>

    <div wire:loading.remove wire:target="loadMemoryData">
    {{-- Memory Overview --}}
    <div class="card bg-base-200 shadow">
        <div class="card-body">
            <h3 class="text-lg font-semibold mb-4">{{ __('Memory Overview') }}</h3>
            <p class="text-sm text-base-content/70 mb-4">
                {{ __('View what Flint remembers about your data and how it learns from your feedback.') }}
            </p>

            {{-- Last Execution Times --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                <div class="stat bg-base-100 rounded-lg">
                    <div class="stat-title">{{ __('Last Digest') }}</div>
                    <div class="stat-value text-lg">
                        @if ($workingMemory['last_execution']['digest'] ?? null)
                            {{ \Carbon\Carbon::parse($workingMemory['last_execution']['digest'])->diffForHumans() }}
                        @else
                            {{ __('Never') }}
                        @endif
                    </div>
                </div>
                <div class="stat bg-base-100 rounded-lg">
                    <div class="stat-title">{{ __('Patterns Detected') }}</div>
                    <div class="stat-value text-lg">{{ count($patterns) }}</div>
                    <div class="stat-desc">{{ __('Last 90 days') }}</div>
                </div>
                <div class="stat bg-base-100 rounded-lg">
                    <div class="stat-title">{{ __('Feedback Given') }}</div>
                    <div class="stat-value text-lg">{{ $feedbackStats['total_feedback_count'] ?? 0 }}</div>
                    <div class="stat-desc">
                        @if (($feedbackStats['rating_average'] ?? 0) > 0)
                            {{ __('Avg rating:') }} {{ number_format($feedbackStats['rating_average'], 1) }}/5
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Domain Insights --}}
    @if (!empty($workingMemory['domain_insights']))
    <div class="card bg-base-200 shadow">
        <div class="card-body">
            <h3 class="text-lg font-semibold mb-4">{{ __('Current Domain Insights') }}</h3>
            <p class="text-sm text-base-content/70 mb-4">
                {{ __('Active insights from each domain agent in working memory.') }}
            </p>

            <div class="space-y-4">
                @foreach ($workingMemory['domain_insights'] as $domain => $insight)
                    @if ($insight)
                    <div class="collapse collapse-arrow bg-base-100">
                        <input type="checkbox" />
                        <div class="collapse-title font-medium flex items-center gap-2">
                            <span class="badge badge-primary badge-sm">{{ ucfirst($domain) }}</span>
                            @if (isset($insight['last_updated']))
                                <span class="text-xs text-base-content/60">
                                    {{ __('Updated') }} {{ \Carbon\Carbon::parse($insight['last_updated'])->diffForHumans() }}
                                </span>
                            @endif
                        </div>
                        <div class="collapse-content">
                            @if (!empty($insight['insights']))
                                <div class="space-y-2 mb-3">
                                    <div class="text-sm font-semibold">{{ __('Insights:') }}</div>
                                    @foreach ($insight['insights'] as $item)
                                        <div class="alert alert-info p-2">
                                            <div class="flex-1">
                                                <div class="text-sm font-medium">{{ $item['title'] ?? 'Insight' }}</div>
                                                <div class="text-xs">{{ $item['description'] ?? '' }}</div>
                                                @if (isset($item['confidence']))
                                                    <div class="badge badge-xs mt-1">{{ __('Confidence:') }} {{ round($item['confidence'] * 100) }}%</div>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            @if (!empty($insight['suggestions']))
                                <div class="space-y-2">
                                    <div class="text-sm font-semibold">{{ __('Suggestions:') }}</div>
                                    @foreach ($insight['suggestions'] as $suggestion)
                                        <div class="alert alert-success p-2">
                                            <div class="flex-1">
                                                <div class="text-sm font-medium">{{ $suggestion['title'] ?? 'Suggestion' }}</div>
                                                <div class="text-xs">{{ $suggestion['description'] ?? '' }}</div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                    @endif
                @endforeach
            </div>
        </div>
    </div>
    @endif

    {{-- Prioritized Actions --}}
    @if (!empty($workingMemory['actions']))
    <div class="card bg-base-200 shadow">
        <div class="card-body">
            <h3 class="text-lg font-semibold mb-4">{{ __('Prioritized Actions') }}</h3>
            <div class="space-y-2">
                @foreach ($workingMemory['actions'] as $action)
                    <div class="alert {{ $action['priority'] === 'high' ? 'alert-warning' : 'alert-info' }} p-3">
                        <div class="flex-1">
                            <div class="flex items-center gap-2 mb-1">
                                <span class="text-sm font-medium">{{ $action['title'] ?? 'Action' }}</span>
                                <span class="badge badge-sm">{{ ucfirst($action['priority'] ?? 'medium') }}</span>
                            </div>
                            <div class="text-xs">{{ $action['description'] ?? '' }}</div>
                            @if (!empty($action['source_domains']))
                                <div class="flex gap-1 mt-1">
                                    @foreach ($action['source_domains'] as $domain)
                                        <span class="badge badge-xs">{{ ucfirst($domain) }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
    @endif

    {{-- Detected Patterns --}}
    @if (!empty($patterns))
    <div class="card bg-base-200 shadow">
        <div class="card-body">
            <h3 class="text-lg font-semibold mb-4">{{ __('Detected Patterns') }}</h3>
            <p class="text-sm text-base-content/70 mb-4">
                {{ __('Long-term patterns discovered by analyzing your historical data.') }}
            </p>
            <div class="space-y-2">
                @foreach (array_slice($patterns, 0, 10) as $pattern)
                    <div class="collapse collapse-arrow bg-base-100">
                        <input type="checkbox" />
                        <div class="collapse-title font-medium text-sm">
                            {{ $pattern['title'] ?? 'Pattern' }}
                            @if (isset($pattern['metadata']['confidence']))
                                <span class="badge badge-xs ml-2">{{ round($pattern['metadata']['confidence'] * 100) }}%</span>
                            @endif
                        </div>
                        <div class="collapse-content">
                            <div class="text-xs text-base-content/70">
                                {{ $pattern['metadata']['description'] ?? '' }}
                            </div>
                            @if (!empty($pattern['metadata']['pattern_type']))
                                <div class="badge badge-sm mt-2">{{ ucfirst($pattern['metadata']['pattern_type']) }}</div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
    @endif

    {{-- Empty State --}}
    @if (empty($workingMemory['domain_insights']) && empty($workingMemory['actions']) && empty($patterns))
    <div class="card bg-base-200 shadow">
        <div class="card-body text-center py-12">
            <x-icon name="o-cpu-chip" class="w-16 h-16 mx-auto text-base-content/30 mb-4" />
            <h3 class="text-lg font-semibold mb-2">{{ __('No Memory Data Yet') }}</h3>
            <p class="text-sm text-base-content/60">
                {{ __('Flint will start building memory as it analyzes your data and generates digests.') }}
            </p>
        </div>
    </div>
    @endif
    </div>
</div>
