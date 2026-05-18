<?php

namespace App\Jobs\TaskPipeline\Tasks;

use App\Jobs\TaskPipeline\BaseTaskJob;
use App\Models\Event;
use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use Illuminate\Support\Facades\Log;

class DetectTrendsTask extends BaseTaskJob
{
    protected const CHANGE_THRESHOLD = 0.15; // 15% change

    /**
     * Execute trend detection for the event's metric combination
     */
    protected function execute(): void
    {
        if ($this->model->value === null || $this->model->value_unit === null) {
            return;
        }

        $userId = $this->model->integration->user_id ?? null;
        if (! $userId) {
            return;
        }

        $metric = MetricStatistic::where('user_id', $userId)
            ->where('service', $this->model->service)
            ->where('action', $this->model->action)
            ->where('value_unit', $this->model->value_unit)
            ->first();

        if (! $metric || ! $metric->hasValidStatistics()) {
            return;
        }

        $this->detectWeeklyTrends($metric);
        $this->detectMonthlyTrends($metric);
        $this->detectQuarterlyTrends($metric);
    }

    protected function detectWeeklyTrends(MetricStatistic $metric): void
    {
        $currentWeekStart = now()->startOfWeek();
        $currentWeekEnd = now()->endOfWeek();
        $currentAvg = $this->getAverageForPeriod($metric, $currentWeekStart, $currentWeekEnd);

        if ($currentAvg === null) {
            return;
        }

        foreach ([4, 8, 12] as $weeks) {
            $comparisonStart = $currentWeekStart->copy()->subWeeks($weeks);
            $comparisonEnd = $currentWeekStart->copy()->subDay();
            $comparisonAvg = $this->getAverageForPeriod($metric, $comparisonStart, $comparisonEnd);

            if ($comparisonAvg === null || $comparisonAvg == 0) {
                continue;
            }

            $percentChange = abs(($currentAvg - $comparisonAvg) / $comparisonAvg);

            if ($percentChange >= self::CHANGE_THRESHOLD) {
                $direction = $currentAvg > $comparisonAvg ? 'up' : 'down';
                $this->createTrendIfNew($metric, "trend_{$direction}_weekly", $currentWeekStart, $currentWeekEnd, $comparisonAvg, $currentAvg, $percentChange, [
                    'comparison_weeks' => $weeks,
                    'comparison_start' => $comparisonStart->toDateString(),
                    'comparison_end' => $comparisonEnd->toDateString(),
                ]);
                break;
            }
        }
    }

    protected function detectMonthlyTrends(MetricStatistic $metric): void
    {
        $currentMonthStart = now()->startOfMonth();
        $currentMonthEnd = now()->endOfMonth();
        $currentAvg = $this->getAverageForPeriod($metric, $currentMonthStart, $currentMonthEnd);

        if ($currentAvg === null) {
            return;
        }

        foreach ([3, 6, 12] as $months) {
            $comparisonStart = $currentMonthStart->copy()->subMonths($months);
            $comparisonEnd = $currentMonthStart->copy()->subDay();
            $comparisonAvg = $this->getAverageForPeriod($metric, $comparisonStart, $comparisonEnd);

            if ($comparisonAvg === null || $comparisonAvg == 0) {
                continue;
            }

            $percentChange = abs(($currentAvg - $comparisonAvg) / $comparisonAvg);

            if ($percentChange >= self::CHANGE_THRESHOLD) {
                $direction = $currentAvg > $comparisonAvg ? 'up' : 'down';
                $this->createTrendIfNew($metric, "trend_{$direction}_monthly", $currentMonthStart, $currentMonthEnd, $comparisonAvg, $currentAvg, $percentChange, [
                    'comparison_months' => $months,
                    'comparison_start' => $comparisonStart->toDateString(),
                    'comparison_end' => $comparisonEnd->toDateString(),
                ]);
                break;
            }
        }
    }

    protected function detectQuarterlyTrends(MetricStatistic $metric): void
    {
        $currentQuarterStart = now()->startOfQuarter();
        $currentQuarterEnd = now()->endOfQuarter();
        $currentAvg = $this->getAverageForPeriod($metric, $currentQuarterStart, $currentQuarterEnd);

        if ($currentAvg === null) {
            return;
        }

        foreach ([2, 4] as $quarters) {
            $comparisonStart = $currentQuarterStart->copy()->subQuarters($quarters);
            $comparisonEnd = $currentQuarterStart->copy()->subDay();
            $comparisonAvg = $this->getAverageForPeriod($metric, $comparisonStart, $comparisonEnd);

            if ($comparisonAvg === null || $comparisonAvg == 0) {
                continue;
            }

            $percentChange = abs(($currentAvg - $comparisonAvg) / $comparisonAvg);

            if ($percentChange >= self::CHANGE_THRESHOLD) {
                $direction = $currentAvg > $comparisonAvg ? 'up' : 'down';
                $this->createTrendIfNew($metric, "trend_{$direction}_quarterly", $currentQuarterStart, $currentQuarterEnd, $comparisonAvg, $currentAvg, $percentChange, [
                    'comparison_quarters' => $quarters,
                    'comparison_start' => $comparisonStart->toDateString(),
                    'comparison_end' => $comparisonEnd->toDateString(),
                ]);
                break;
            }
        }
    }

    protected function createTrendIfNew(
        MetricStatistic $metric,
        string $type,
        $periodStart,
        $periodEnd,
        float $baselineValue,
        float $currentValue,
        float $deviation,
        array $metadata,
    ): void {
        $existing = MetricTrend::where('metric_statistic_id', $metric->id)
            ->where('type', $type)
            ->whereNull('acknowledged_at')
            ->where('start_date', '>=', $periodStart->toDateString())
            ->exists();

        if ($existing) {
            return;
        }

        MetricTrend::create([
            'metric_statistic_id' => $metric->id,
            'type' => $type,
            'detected_at' => now(),
            'start_date' => $periodStart->toDateString(),
            'end_date' => $periodEnd->toDateString(),
            'baseline_value' => $baselineValue,
            'current_value' => $currentValue,
            'deviation' => $deviation,
            'significance_score' => min($deviation / self::CHANGE_THRESHOLD, 1.0),
            'metadata' => $metadata,
        ]);

        Log::info('Detected metric trend via TaskPipeline', [
            'event_id' => $this->model->id,
            'metric_id' => $metric->id,
            'type' => $type,
            'percent_change' => $deviation * 100,
        ]);
    }

    protected function getAverageForPeriod(MetricStatistic $metric, $startDate, $endDate): ?float
    {
        $events = Event::whereHas('integration', function ($q) use ($metric) {
            $q->where('user_id', $metric->user_id)->whereNull('deleted_at');
        })
            ->where('service', $metric->service)
            ->where('action', $metric->action)
            ->where('value_unit', $metric->value_unit)
            ->whereNotNull('value')
            ->whereNull('deleted_at')
            ->whereBetween('time', [$startDate, $endDate])
            ->get();

        if ($events->isEmpty()) {
            return null;
        }

        $values = $events->map(fn ($e) => $e->getFormattedValueAttribute())->filter();

        return $values->isEmpty() ? null : $values->average();
    }
}
