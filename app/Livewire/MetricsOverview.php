<?php

namespace App\Livewire;

use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class MetricsOverview extends Component
{
    public string $filterService = '';

    public string $filterDomain = '';

    public string $sortBy = 'interesting'; // interesting, service, recent

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

        // Get metrics with their trend counts
        $metrics = $metricsQuery->get()->map(function ($metric) {
            $metric->unacknowledged_trends_count = $metric->trends()
                ->unacknowledged()
                ->count();

            $metric->recent_anomalies_count = $metric->trends()
                ->anomalies()
                ->unacknowledged()
                ->where('detected_at', '>=', now()->subDays(7))
                ->count();

            return $metric;
        });

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

        // Get recent unacknowledged trends
        $recentTrends = MetricTrend::whereHas('metricStatistic', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })
            ->unacknowledged()
            ->with('metricStatistic')
            ->orderByDesc('detected_at')
            ->limit(10)
            ->get();

        return view('livewire.metrics-overview', [
            'metrics' => $metrics,
            'services' => $services,
            'recentTrends' => $recentTrends,
        ]);
    }
}
