<?php

namespace App\Jobs\Metrics;

use App\Models\Event;
use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DetectMetricAnomaliesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 60;

    public $tries = 2;

    public $backoff = [30, 60];

    protected Event $event;

    /**
     * Create a new job instance.
     */
    public function __construct(Event $event)
    {
        $this->event = $event;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Skip if event has no value
        if ($this->event->value === null || $this->event->value_unit === null) {
            return;
        }

        // Check if user has this metric disabled
        $identifier = "{$this->event->service}.{$this->event->action}.{$this->event->value_unit}";
        if ($this->isMetricDisabled($identifier)) {
            return;
        }

        // Check if user has overridden the anomaly detection mode for this metric
        if ($this->hasUserOverrideDisabled()) {
            return;
        }

        // Find the metric statistic
        $metricStatistic = MetricStatistic::where('user_id', $this->event->integration->user_id)
            ->where('service', $this->event->service)
            ->where('action', $this->event->action)
            ->where('value_unit', $this->event->value_unit)
            ->first();

        if (! $metricStatistic || ! $metricStatistic->hasValidStatistics()) {
            // No statistics yet or insufficient data
            return;
        }

        // Get the event's formatted value
        $value = $this->event->getFormattedValueAttribute();

        if ($value === null) {
            return;
        }

        // Check if value is outside normal bounds
        $isAnomalyHigh = $value > $metricStatistic->normal_upper_bound;
        $isAnomalyLow = $value < $metricStatistic->normal_lower_bound;

        if (! $isAnomalyHigh && ! $isAnomalyLow) {
            // Value is within normal range
            return;
        }

        // Determine anomaly type
        $type = $isAnomalyHigh ? 'anomaly_high' : 'anomaly_low';

        // Check if we've already recorded this anomaly
        $existingAnomaly = MetricTrend::where('metric_statistic_id', $metricStatistic->id)
            ->where('type', $type)
            ->whereJsonContains('metadata->event_id', $this->event->id)
            ->first();

        if ($existingAnomaly) {
            // Already recorded
            return;
        }

        // Calculate how many standard deviations away
        $deviation = abs($value - $metricStatistic->mean_value) / $metricStatistic->stddev_value;

        // Create the anomaly record
        MetricTrend::create([
            'metric_statistic_id' => $metricStatistic->id,
            'type' => $type,
            'detected_at' => $this->event->time,
            'start_date' => $this->event->time->toDateString(),
            'end_date' => $this->event->time->toDateString(),
            'baseline_value' => $metricStatistic->mean_value,
            'current_value' => $value,
            'deviation' => $deviation,
            'significance_score' => min($deviation / 2, 1.0), // Normalize to 0-1 scale
            'metadata' => [
                'event_id' => $this->event->id,
                'normal_lower_bound' => $metricStatistic->normal_lower_bound,
                'normal_upper_bound' => $metricStatistic->normal_upper_bound,
                'stddev' => $metricStatistic->stddev_value,
            ],
        ]);

        Log::info('Detected metric anomaly', [
            'event_id' => $this->event->id,
            'service' => $this->event->service,
            'action' => $this->event->action,
            'value_unit' => $this->event->value_unit,
            'type' => $type,
            'value' => $value,
            'mean' => $metricStatistic->mean_value,
            'deviation' => $deviation,
        ]);
    }

    /**
     * Check if metric tracking is disabled for this event's user
     */
    protected function isMetricDisabled(string $identifier): bool
    {
        $user = $this->event->integration->user;
        $settings = $user->settings ?? [];
        $metricTracking = $settings['metric_tracking'] ?? [];
        $disabledMetrics = $metricTracking['disabled_metrics'] ?? [];

        return in_array($identifier, $disabledMetrics);
    }

    /**
     * Check if user has overridden anomaly detection mode to disabled
     */
    protected function hasUserOverrideDisabled(): bool
    {
        $user = $this->event->integration->user;
        $override = $user->getAnomalyDetectionModeOverride(
            $this->event->service,
            $this->event->action,
            $this->event->value_unit
        );

        return $override === 'disabled';
    }
}
