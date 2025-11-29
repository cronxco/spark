<?php

namespace App\Jobs\TaskPipeline\Tasks;

use App\Jobs\TaskPipeline\BaseTaskJob;

class CalculateMetricStatsTask extends BaseTaskJob
{
    /**
     * Execute the metric statistics calculation task
     */
    protected function execute(): void
    {
        // TODO: Implement metric statistics calculation
        // This task calculates mean, stddev, min/max for metrics

        // Example implementation:
        // $metric = MetricStatistic::firstOrNew([
        //     'user_id' => $this->model->integration->user_id,
        //     'service' => $this->model->service,
        //     'action' => $this->model->action,
        //     'value_unit' => $this->model->value_unit,
        // ]);
        //
        // // Get all events for this metric
        // $events = Event::where('service', $this->model->service)
        //     ->where('action', $this->model->action)
        //     ->where('value_unit', $this->model->value_unit)
        //     ->where('created_at', '>=', now()->subDays(90))
        //     ->get();
        //
        // if ($events->count() >= 10) {
        //     $values = $events->pluck('value');
        //     $mean = $values->avg();
        //     $stddev = $this->calculateStdDev($values, $mean);
        //
        //     $metric->fill([
        //         'mean' => $mean,
        //         'stddev' => $stddev,
        //         'min' => $values->min(),
        //         'max' => $values->max(),
        //         'normal_lower' => $mean - (2 * $stddev),
        //         'normal_upper' => $mean + (2 * $stddev),
        //         'sample_size' => $events->count(),
        //         'calculated_at' => now(),
        //     ]);
        //
        //     $metric->save();
        // }
    }

    /**
     * Calculate standard deviation
     */
    protected function calculateStdDev($values, $mean): float
    {
        $variance = $values->map(function ($value) use ($mean) {
            return pow($value - $mean, 2);
        })->avg();

        return sqrt($variance);
    }
}
