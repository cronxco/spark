<?php

namespace App\Livewire;

use App\Jobs\Metrics\CalculateMetricStatisticsJob;
use App\Jobs\Metrics\DetectMetricTrendsJob;
use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Mary\Traits\Toast;

class MetricsOverview extends Component
{
    use Toast;

    public string $filterService = '';

    public string $filterDomain = '';

    public string $sortBy = 'interesting'; // interesting, service, recent

    // Progressive loading state
    public bool $recentTrendsLoaded = false;

    public $cachedRecentTrends = null;

    public function loadRecentTrends(): void
    {
        if ($this->recentTrendsLoaded) {
            return;
        }

        $user = Auth::user();

        $this->cachedRecentTrends = MetricTrend::whereHas('metricStatistic', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })
            ->trends()
            ->unacknowledged()
            ->with('metricStatistic')
            ->orderByDesc('detected_at')
            ->limit(10)
            ->get();

        $this->recentTrendsLoaded = true;
    }

    public function calculateStatistics(): void
    {
        CalculateMetricStatisticsJob::dispatch();

        $this->success('Statistics calculation job dispatched. This may take a few minutes.');
    }

    public function detectTrends(): void
    {
        DetectMetricTrendsJob::dispatch();

        $this->success('Trend detection job dispatched. This may take a few minutes.');
    }

    public function render()
    {
        $user = Auth::user();

        // Get all metrics for the user
        $metricsQuery = MetricStatistic::where('user_id', $user->id)
            ->withSufficientData();

        // Apply filters
        if ($this->filterService) {
            $metricsQuery->where('service', $this->filterService);
        }

        // Get metrics with their trend counts using withCount (N+1 optimization)
        // Count only actual trends (not anomalies) that are unacknowledged
        $metrics = $metricsQuery->withCount(['trends as unacknowledged_trends_count' => function ($query) {
            $query->where('type', 'like', 'trend_%')
                ->whereNull('acknowledged_at');
        }])->get();

        // Sort metrics
        $metrics = match ($this->sortBy) {
            'interesting' => $metrics->sortByDesc('unacknowledged_trends_count'),
            'service' => $metrics->sortBy('service'),
            'recent' => $metrics->sortByDesc('last_event_at'),
            default => $metrics,
        };

        // Get available services and domains for filters
        $services = MetricStatistic::where('user_id', $user->id)
            ->distinct()
            ->pluck('service')
            ->sort();

        return view('livewire.metrics-overview', [
            'metrics' => $metrics,
            'services' => $services,
            'recentTrends' => $this->cachedRecentTrends ?? collect(),
            'recentTrendsLoaded' => $this->recentTrendsLoaded,
        ]);
    }

    protected function getListeners(): array
    {
        return [
            // Spotlight command events
            'calculate-statistics' => 'calculateStatistics',
            'detect-trends' => 'detectTrends',
        ];
    }
}
