<?php

namespace App\Jobs\TaskPipeline\Tasks;

use App\Integrations\PluginRegistry;
use App\Jobs\TaskPipeline\BaseTaskJob;
use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class DetectAnomaliesTask extends BaseTaskJob
{
    /**
     * Recorded when a daily aggregate was left unjudged because its day had
     * not ended. The retrospective sweep runs these again once it has.
     */
    public const OUTCOME_DAY_IN_PROGRESS = 'day_in_progress';

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

        // A daily aggregate that is still filling up (Oura's heart-rate
        // average is written at 03:00 from a few overnight readings, then
        // rewritten through the day) is not a reading yet. Judging it early
        // raised a "below its usual range" anomaly every morning, and the
        // streak of those stale rows read as "4 days in a row".
        if ($this->isDayStillAccumulating()) {
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

        // Check if value is outside normal bounds
        $isAnomalyHigh = $value > $metricStatistic->normal_upper_bound;
        $isAnomalyLow = $value < $metricStatistic->normal_lower_bound;

        if (! $isAnomalyHigh && ! $isAnomalyLow) {
            // Back within range: an anomaly raised from an earlier value of
            // this event no longer describes it.
            $this->clearAnomaliesForEvent($metricStatistic);

            return;
        }

        // Determine anomaly type
        $type = $isAnomalyHigh ? 'anomaly_high' : 'anomaly_low';

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

        // Calculate how many standard deviations away
        $deviation = abs($value - $metricStatistic->mean_value) / $metricStatistic->stddev_value;

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
            'baseline_value' => $metricStatistic->mean_value,
            'current_value' => $value,
            'deviation' => $deviation,
            'significance_score' => tanh($deviation / 2),
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
     * Whether this event is a daily aggregate for a day that has not ended
     * yet in the user's timezone. Plugins opt in per action with
     * `accumulates_through_day`.
     */
    protected function isDayStillAccumulating(): bool
    {
        $plugin = PluginRegistry::getPlugin($this->model->service);
        $config = $plugin ? ($plugin::getActionTypes()[$this->model->action] ?? []) : [];

        if (! ($config['accumulates_through_day'] ?? false)) {
            return false;
        }

        // Daily aggregates are stamped at 00:00 UTC on the day they cover.
        // That day is over once it has ended both in UTC (where the source
        // groups its readings) and in the user's timezone; west of UTC,
        // converting the stamp would land on the previous local day.
        $day = Carbon::parse($this->model->time)->utc()->toDateString();
        $timezone = $this->model->integration->user?->getTimezone() ?? 'UTC';
        $dayEnd = Carbon::parse($day, 'UTC')->endOfDay()
            ->max(Carbon::parse($day, $timezone)->endOfDay());

        return now()->lessThanOrEqualTo($dayEnd);
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
