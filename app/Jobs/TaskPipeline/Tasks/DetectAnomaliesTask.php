<?php

namespace App\Jobs\TaskPipeline\Tasks;

use App\Jobs\TaskPipeline\BaseTaskJob;
use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use App\Services\Metrics\AnomalyPolicy;
use App\Services\Metrics\BaselineService;
use App\Services\Metrics\MetricCatalogue;
use App\Services\Metrics\MetricCompleteness;
use Illuminate\Support\Facades\Log;

class DetectAnomaliesTask extends BaseTaskJob
{
    /**
     * Recorded when a daily aggregate was left unjudged because its day had
     * not ended. The retrospective sweep runs these again once it has.
     */
    public const OUTCOME_DAY_IN_PROGRESS = 'day_in_progress';

    /**
     * Recorded on every other run, so a deferral from an earlier run of the
     * same event doesn't linger in its merged task metadata.
     */
    public const OUTCOME_CHECKED = 'checked';

    /**
     * Execute the anomaly detection task
     */
    protected function execute(): void
    {
        $this->recordOutcome(self::OUTCOME_CHECKED);

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

        // Only listed metrics are judged. One that has dropped off the list
        // (or was never on it) leaves nothing behind from earlier runs.
        if (! app(MetricCatalogue::class)->raisesAnomalies($this->model->service, $this->model->action)) {
            $this->clearAnomaliesForEvent($metricStatistic);

            return;
        }

        // A value that is still filling up (Oura's heart-rate average is
        // written at 03:00 from a few overnight readings, then rewritten
        // through the day) or that its source is still revising is not a
        // reading yet. Judging it early raised a "below its usual range"
        // anomaly every morning, and the streak of those stale rows read as
        // "4 days in a row".
        if (! $this->isFinal()) {
            $this->clearAnomaliesForEvent($metricStatistic);
            $this->recordOutcome(self::OUTCOME_DAY_IN_PROGRESS);

            return;
        }

        // Get the event's formatted value
        $value = $this->model->getFormattedValueAttribute();

        if ($value === null) {
            return;
        }

        // Outside money, zero means "no reading" (the baseline leaves zeros
        // out too), so it can't be judged and an earlier anomaly is cleared.
        if ($value == 0 && $this->model->domain !== 'money') {
            $this->clearAnomaliesForEvent($metricStatistic);

            return;
        }

        // Judge against the history before this day, not one that contains it.
        $basis = app(BaselineService::class)->excluding(
            $metricStatistic,
            $this->model->time,
            $this->model->integration->user?->getTimezone() ?? 'UTC',
        );

        $verdict = app(AnomalyPolicy::class)->judge($basis, (float) $value);

        if ($verdict === null) {
            // Back within range, or a move in the good direction: an anomaly
            // raised from an earlier value of this event no longer describes it.
            $this->clearAnomaliesForEvent($metricStatistic);

            return;
        }

        $type = $verdict['type'];
        $deviation = $verdict['deviation'];

        // An earlier value of this event may have tipped the other way.
        $this->clearAnomaliesForEvent($metricStatistic, except: $type);

        // Skip if the user has actively suppressed this direction
        $suppressionColumn = $type === 'anomaly_high' ? 'anomaly_high_suppressed_until' : 'anomaly_low_suppressed_until';
        if ($metricStatistic->{$suppressionColumn} && now()->isBefore($metricStatistic->{$suppressionColumn})) {
            return;
        }

        // Check if we've already recorded this anomaly
        $existingAnomaly = MetricTrend::where('metric_statistic_id', $metricStatistic->id)
            ->where('type', $type)
            ->whereJsonContains('metadata->event_id', $this->model->id)
            ->first();

        if ($existingAnomaly) {
            // Already recorded: keep it describing the event's latest value.
            $existingAnomaly->update([
                'current_value' => $value,
                'deviation' => $deviation,
                'significance_score' => tanh($deviation / 2),
            ]);

            return;
        }

        // Create the anomaly record
        MetricTrend::create([
            'metric_statistic_id' => $metricStatistic->id,
            'type' => $type,
            'detected_at' => $this->model->time,
            'start_date' => $this->model->time->toDateString(),
            'end_date' => $this->model->time->toDateString(),
            'baseline_value' => $basis->mean_value,
            'current_value' => $value,
            'deviation' => $deviation,
            'significance_score' => tanh($deviation / 2),
            'metadata' => [
                'event_id' => $this->model->id,
                'normal_lower_bound' => $basis->normal_lower_bound,
                'normal_upper_bound' => $basis->normal_upper_bound,
                'stddev' => $basis->stddev_value,
            ],
        ]);

        Log::info('Detected metric anomaly via TaskPipeline', [
            'event_id' => $this->model->id,
            'service' => $this->model->service,
            'action' => $this->model->action,
            'value_unit' => $this->model->value_unit,
            'type' => $type,
            'value' => $value,
            'mean' => $basis->mean_value,
            'deviation' => $deviation,
        ]);
    }

    /**
     * Whether the event's value is final: its day has ended (for metrics that
     * fill in through the day) and its source has stopped rewriting it.
     */
    protected function isFinal(): bool
    {
        $timezone = $this->model->integration->user?->getTimezone() ?? 'UTC';

        return app(MetricCompleteness::class)->isFinal(
            $this->model->service,
            $this->model->action,
            $this->model->time,
            $timezone,
            updatedAt: $this->model->updated_at,
        );
    }

    /**
     * Remove unacknowledged anomalies raised from earlier values of this
     * event. Acknowledged ones are kept, since the user has acted on them.
     */
    protected function clearAnomaliesForEvent(MetricStatistic $metricStatistic, ?string $except = null): void
    {
        MetricTrend::where('metric_statistic_id', $metricStatistic->id)
            ->anomalies()
            ->when($except, fn ($query) => $query->where('type', '!=', $except))
            ->whereNull('acknowledged_at')
            ->whereJsonContains('metadata->event_id', $this->model->id)
            ->delete();
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
