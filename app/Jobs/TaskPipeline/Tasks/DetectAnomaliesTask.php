<?php

namespace App\Jobs\TaskPipeline\Tasks;

use App\Jobs\TaskPipeline\BaseTaskJob;
use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use Illuminate\Support\Facades\Log;

class DetectAnomaliesTask extends BaseTaskJob
{
    /**
     * Execute the anomaly detection task
     */
    protected function execute(): void
    {
        // Skip if event has no value
        if ($this->model->value === null || $this->model->value_unit === null) {
            return;
        }

        // Check if user has this metric disabled
        $identifier = "{$this->model->service}.{$this->model->action}.{$this->model->value_unit}";
        if ($this->isMetricDisabled($identifier)) {
            return;
        }

        // Check if user has overridden the anomaly detection mode for this metric
        if ($this->hasUserOverrideDisabled()) {
            return;
        }

        // Find the metric statistic
        $metricStatistic = MetricStatistic::where('user_id', $this->model->integration->user_id)
            ->where('service', $this->model->service)
            ->where('action', $this->model->action)
            ->where('value_unit', $this->model->value_unit)
            ->first();

        if (! $metricStatistic || ! $metricStatistic->hasValidStatistics()) {
            // No statistics yet or insufficient data
            return;
        }

        // Get the event's formatted value
        $value = $this->model->getFormattedValueAttribute();

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
            ->whereJsonContains('metadata->event_id', $this->model->id)
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
            'detected_at' => $this->model->time,
            'start_date' => $this->model->time->toDateString(),
            'end_date' => $this->model->time->toDateString(),
            'baseline_value' => $metricStatistic->mean_value,
            'current_value' => $value,
            'deviation' => $deviation,
            'significance_score' => min($deviation / 2, 1.0), // Normalize to 0-1 scale
            'metadata' => [
                'event_id' => $this->model->id,
                'normal_lower_bound' => $metricStatistic->normal_lower_bound,
                'normal_upper_bound' => $metricStatistic->normal_upper_bound,
                'stddev' => $metricStatistic->stddev_value,
            ],
        ]);

        Log::info('Detected metric anomaly via TaskPipeline', [
            'event_id' => $this->model->id,
            'service' => $this->model->service,
            'action' => $this->model->action,
            'value_unit' => $this->model->value_unit,
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
        $user = $this->model->integration->user;
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
        $user = $this->model->integration->user;
        $override = $user->getAnomalyDetectionModeOverride(
            $this->model->service,
            $this->model->action,
            $this->model->value_unit
        );

        return $override === 'disabled';
    }
}
