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

        $domain = $this->model->domain;
        $windowDays = MetricStatistic::DEFAULT_WINDOW_DAYS;

        // Get events within the rolling window for this metric via integration relationship
        $events = Event::whereHas('integration', function ($query) use ($userId) {
            $query->where('user_id', $userId);
        })
            ->withoutInternal()
            ->where('service', $service)
            ->where('action', $action)
            ->where('value_unit', $valueUnit)
            ->whereNotNull('value')
            ->whereNull('deleted_at')
            ->where('time', '>=', now()->subDays($windowDays))
            ->orderBy('time')
            ->get();

        $identifier = ['user_id' => $userId, 'service' => $service, 'action' => $action, 'value_unit' => $valueUnit];

        if ($events->count() < 10) {
            Log::debug('Insufficient events for metric statistics within window', [
                'user_id' => $userId,
                'service' => $service,
                'action' => $action,
                'value_unit' => $valueUnit,
                'count' => $events->count(),
                'window_days' => $windowDays,
            ]);

            $this->clearMetricStatistics($identifier, $windowDays);

            return;
        }

        // Check if we have at least 30 days of data within the window
        $firstEvent = $events->first();
        $lastEvent = $events->last();
        $daysBetween = $firstEvent->time->diffInDays($lastEvent->time);

        if ($daysBetween < 30) {
            Log::debug('Insufficient time range for metric statistics within window', [
                'user_id' => $userId,
                'service' => $service,
                'action' => $action,
                'value_unit' => $valueUnit,
                'days' => $daysBetween,
                'window_days' => $windowDays,
            ]);

            $this->clearMetricStatistics($identifier, $windowDays);

            return;
        }

        // Financial metrics legitimately record zero values (e.g. spending category
        // with no transactions on a given day). All other domains treat zero as "no
        // reading" and exclude those events from baseline statistics.
        $includeZeros = $domain === 'money';
        $values = $events->map(fn ($event) => $event->getFormattedValueAttribute())
            ->filter(fn ($v) => $v !== null && ($includeZeros || $v != 0))
            ->values();

        if ($values->isEmpty()) {
            return;
        }

        $mean = $values->average();
        $variance = $values->map(fn ($value) => pow($value - $mean, 2))->average();
        $stddev = sqrt($variance);

        // Create or update metric statistic
        MetricStatistic::updateOrCreate(
            $identifier,
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
                'baseline_window_days' => $windowDays,
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
            'window_days' => $windowDays,
        ]);
    }

    /**
     * Null out computed stats on an existing row so stale bounds are not used for anomaly detection.
     *
     * @param  array<string, string>  $identifier
     */
    protected function clearMetricStatistics(array $identifier, int $windowDays): void
    {
        MetricStatistic::where($identifier)->update([
            'event_count' => 0,
            'first_event_at' => null,
            'last_event_at' => null,
            'min_value' => null,
            'max_value' => null,
            'mean_value' => null,
            'stddev_value' => null,
            'normal_lower_bound' => null,
            'normal_upper_bound' => null,
            'baseline_window_days' => $windowDays,
            'last_calculated_at' => now(),
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
