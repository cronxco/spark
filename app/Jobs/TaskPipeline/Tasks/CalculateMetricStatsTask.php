<?php

namespace App\Jobs\TaskPipeline\Tasks;

use App\Jobs\TaskPipeline\BaseTaskJob;
use App\Models\Event;
use App\Models\MetricStatistic;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class CalculateMetricStatsTask extends BaseTaskJob
{
    /**
     * Execute the metric statistics calculation task
     *
     * This calculates baseline statistics for a specific metric combination
     */
    protected function execute(): void
    {
        // This task only applies to events with values
        if ($this->model->value === null || $this->model->value_unit === null) {
            return;
        }

        $userId = $this->model->integration->user_id;
        $service = $this->model->service;
        $action = $this->model->action;
        $valueUnit = $this->model->value_unit;

        // Check if user has this metric disabled
        $identifier = "{$service}.{$action}.{$valueUnit}";
        if ($this->isMetricDisabled($userId, $identifier)) {
            return;
        }

        // Check if user has anomaly detection mode set to disabled for this metric
        $user = User::find($userId);
        if (! $user) {
            return;
        }

        $override = $user->getAnomalyDetectionModeOverride($service, $action, $valueUnit);
        if ($override === 'disabled') {
            return;
        }

        // Get all events for this metric via integration relationship
        $events = Event::whereHas('integration', function ($query) use ($userId) {
            $query->where('user_id', $userId);
        })
            ->where('service', $service)
            ->where('action', $action)
            ->where('value_unit', $valueUnit)
            ->whereNotNull('value')
            ->whereNull('deleted_at')
            ->orderBy('time')
            ->get();

        if ($events->count() < 10) {
            Log::debug('Insufficient events for metric statistics', [
                'user_id' => $userId,
                'service' => $service,
                'action' => $action,
                'value_unit' => $valueUnit,
                'count' => $events->count(),
            ]);

            return;
        }

        // Check if we have at least 30 days of data
        $firstEvent = $events->first();
        $lastEvent = $events->last();
        $daysBetween = $firstEvent->time->diffInDays($lastEvent->time);

        if ($daysBetween < 30) {
            Log::debug('Insufficient time range for metric statistics', [
                'user_id' => $userId,
                'service' => $service,
                'action' => $action,
                'value_unit' => $valueUnit,
                'days' => $daysBetween,
            ]);

            return;
        }

        // Calculate statistics using formatted values
        $values = $events->map(fn ($event) => $event->getFormattedValueAttribute())->filter()->values();

        if ($values->isEmpty()) {
            return;
        }

        $mean = $values->average();
        $variance = $values->map(fn ($value) => pow($value - $mean, 2))->average();
        $stddev = sqrt($variance);

        // Create or update metric statistic
        MetricStatistic::updateOrCreate(
            [
                'user_id' => $userId,
                'service' => $service,
                'action' => $action,
                'value_unit' => $valueUnit,
            ],
            [
                'event_count' => $values->count(),
                'first_event_at' => $firstEvent->time,
                'last_event_at' => $lastEvent->time,
                'min_value' => $values->min(),
                'max_value' => $values->max(),
                'mean_value' => $mean,
                'stddev_value' => $stddev,
                'normal_lower_bound' => $mean - (2 * $stddev),
                'normal_upper_bound' => $mean + (2 * $stddev),
                'last_calculated_at' => now(),
            ]
        );

        Log::info('Calculated metric statistics via TaskPipeline', [
            'user_id' => $userId,
            'service' => $service,
            'action' => $action,
            'value_unit' => $valueUnit,
            'count' => $values->count(),
            'mean' => $mean,
            'stddev' => $stddev,
        ]);
    }

    /**
     * Check if metric tracking is disabled for user
     */
    protected function isMetricDisabled(string $userId, string $identifier): bool
    {
        $user = User::find($userId);
        if (! $user) {
            return false;
        }

        $settings = $user->settings ?? [];
        $metricTracking = $settings['metric_tracking'] ?? [];
        $disabledMetrics = $metricTracking['disabled_metrics'] ?? [];

        return in_array($identifier, $disabledMetrics);
    }
}
