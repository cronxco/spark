<?php

namespace App\Jobs\TaskPipeline\Tasks;

use App\Jobs\TaskPipeline\BaseTaskJob;
use App\Models\MetricStatistic;
use App\Models\User;
use App\Services\Metrics\BaselineService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CalculateMetricStatsTask extends BaseTaskJob
{
    public const DEBOUNCE_SECONDS = 600;

    public static function debounceKey(MetricStatistic $statistic): string
    {
        return "metric-baseline:{$statistic->id}";
    }

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

        $identifier = ['user_id' => $userId, 'service' => $service, 'action' => $action, 'value_unit' => $valueUnit];
        $statistic = MetricStatistic::firstOrNew($identifier);

        // Events for a busy metric arrive in bursts (Apple Health syncs a
        // day's worth at once); the baseline only needs recomputing now and
        // then. The nightly job refreshes every metric regardless.
        if ($statistic->exists && ! Cache::add(self::debounceKey($statistic), 1, self::DEBOUNCE_SECONDS)) {
            return;
        }

        if (app(BaselineService::class)->recompute($statistic)) {
            Log::info('Calculated metric statistics via TaskPipeline', [
                'user_id' => $userId,
                'service' => $service,
                'action' => $action,
                'value_unit' => $valueUnit,
                'count' => $statistic->event_count,
                'centre' => $statistic->mean_value,
                'spread' => $statistic->stddev_value,
            ]);
        }
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
