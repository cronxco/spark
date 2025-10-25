<?php

namespace App\Livewire;

use App\Models\Event;
use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class MetricDetail extends Component
{
    public MetricStatistic $metric;

    public string $timeRange = '30'; // days

    public bool $showMovingAverage = true;

    public bool $showNormalRange = true;

    public bool $showAnomalies = true;

    public bool $showTrends = false;

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

        // Get events for the selected time range
        $startDate = now()->subDays((int) $this->timeRange);
        $events = Event::whereHas('integration', function ($q) use ($user) {
            $q->where('user_id', $user->id)
                ->whereNull('deleted_at');
        })
            ->where('service', $this->metric->service)
            ->where('action', $this->metric->action)
            ->where('value_unit', $this->metric->value_unit)
            ->whereNotNull('value')
            ->whereNull('deleted_at')
            ->where('time', '>=', $startDate)
            ->orderBy('time')
            ->get();

        // Prepare chart data
        $chartLabels = $events->pluck('time')->map(fn ($time) => $time->format('Y-m-d'))->toArray();
        $chartData = $events->map(fn ($event) => $event->getFormattedValueAttribute())->toArray();

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

        return view('livewire.metric-detail', [
            'events' => $events,
            'chartLabels' => $chartLabels,
            'chartData' => $chartData,
            'anomalies' => $trends['anomalies'] ?? collect(),
            'detectedTrends' => $trends['trends'] ?? collect(),
            'isTrackingDisabled' => $isTrackingDisabled,
        ]);
    }
}
