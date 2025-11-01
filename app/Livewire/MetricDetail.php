<?php

namespace App\Livewire;

use App\Jobs\CalculateMetricStatisticsJob;
use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class MetricDetail extends Component
{
    public MetricStatistic $metric;

    public int $timeRange = 30;

    public bool $showNormalRange = true;

    public bool $showAnomalies = true;

    public bool $showMovingAverage = false;

    public function mount(MetricStatistic $metric): void
    {
        // Ensure user owns this metric
        if ($metric->user_id !== Auth::id()) {
            abort(403);
        }

        $this->metric = $metric;
    }

    public function acknowledgeTrend(string $trendId): void
    {
        $trend = MetricTrend::findOrFail($trendId);

        // Ensure trend belongs to this metric
        if ($trend->metric_statistic_id !== $this->metric->id) {
            abort(403);
        }

        $trend->acknowledge();
    }

    public function acknowledgeAllTrends(): void
    {
        // Acknowledge all unacknowledged trends for this metric
        MetricTrend::where('metric_statistic_id', $this->metric->id)
            ->whereNull('acknowledged_at')
            ->get()
            ->each(fn ($trend) => $trend->acknowledge());

        $this->dispatch('trends-acknowledged');
    }

    public function calculateMetricStatistics(): void
    {
        // Dispatch job to calculate statistics for all metrics
        // Note: The job recalculates all metrics, including this one
        CalculateMetricStatisticsJob::dispatch();

        $this->dispatch('statistics-calculation-started');
    }

    public function toggleTracking(): void
    {
        $user = Auth::user();

        if ($user->isMetricTrackingDisabled($this->metric->service, $this->metric->action, $this->metric->value_unit)) {
            $user->enableMetricTracking($this->metric->service, $this->metric->action, $this->metric->value_unit);
        } else {
            $user->disableMetricTracking($this->metric->service, $this->metric->action, $this->metric->value_unit);
        }

        $this->redirect(route('metrics.show', $this->metric->id));
    }

    public function render()
    {
        $user = Auth::user();

        // Get trends for this metric
        $trends = $this->metric->trends()
            ->orderByDesc('detected_at')
            ->get()
            ->groupBy(fn ($trend) => $trend->isAnomaly() ? 'anomalies' : 'trends');

        // Check if tracking is disabled
        $isTrackingDisabled = $user->isMetricTrackingDisabled(
            $this->metric->service,
            $this->metric->action,
            $this->metric->value_unit
        );

        // Prepare chart data
        $chartLabels = [];
        $chartData = [];

        return view('livewire.metric-detail', [
            'anomalies' => $trends['anomalies'] ?? collect(),
            'detectedTrends' => $trends['trends'] ?? collect(),
            'isTrackingDisabled' => $isTrackingDisabled,
            'chartLabels' => $chartLabels,
            'chartData' => $chartData,
            'showNormalRange' => $this->showNormalRange,
        ]);
    }

    protected function getListeners(): array
    {
        return [
            // Spotlight command events
            'acknowledge-all-trends' => 'acknowledgeAllTrends',
            'calculate-metric-statistics' => 'calculateMetricStatistics',
        ];
    }
}
