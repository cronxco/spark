<?php

namespace App\Jobs\Metrics;

use App\Models\Event;
use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\TransactionContext;

class DetectMetricTrendsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected const CHANGE_THRESHOLD = 0.15; // 15% change

    protected const SIGNIFICANCE_LEVEL = 0.05; // 5% significance level for t-test

    public $timeout = 900; // 15 minutes

    public $tries = 2;

    public $backoff = [300, 600];

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $hub = SentrySdk::getCurrentHub();
        $txContext = new TransactionContext;
        $txContext->setName('job.metrics:detect_trends');
        $txContext->setOp('job');
        $transaction = $hub->startTransaction($txContext);
        $hub->setSpan($transaction);

        try {
            Log::info('Starting metric trend detection');

            // Get all metrics with sufficient data
            $metrics = MetricStatistic::withSufficientData()->get();

            Log::info('Found metrics for trend detection', ['count' => $metrics->count()]);

            foreach ($metrics as $metric) {
                $this->detectTrendsForMetric($metric);
            }

            Log::info('Completed metric trend detection');
            $transaction->setStatus(SpanStatus::ok());
        } catch (Exception $e) {
            Log::error('Failed metric trend detection', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $transaction->setStatus(SpanStatus::internalError());
            throw $e;
        } finally {
            $transaction->finish();
            $hub->setSpan(null);
        }
    }

    /**
     * Detect trends for a specific metric
     */
    protected function detectTrendsForMetric(MetricStatistic $metric): void
    {
        // Detect weekly trends
        $this->detectWeeklyTrends($metric);

        // Detect monthly trends
        $this->detectMonthlyTrends($metric);

        // Detect quarterly trends
        $this->detectQuarterlyTrends($metric);
    }

    /**
     * Detect weekly trends
     */
    protected function detectWeeklyTrends(MetricStatistic $metric): void
    {
        $currentWeekStart = now()->startOfWeek();
        $currentWeekEnd = now()->endOfWeek();

        // Get current week average
        $currentWeekAvg = $this->getAverageForPeriod(
            $metric,
            $currentWeekStart,
            $currentWeekEnd
        );

        if ($currentWeekAvg === null) {
            return;
        }

        // Compare against previous 4, 8, and 12 weeks
        foreach ([4, 8, 12] as $weeks) {
            $comparisonStart = $currentWeekStart->copy()->subWeeks($weeks);
            $comparisonEnd = $currentWeekStart->copy()->subDay();

            $comparisonAvg = $this->getAverageForPeriod(
                $metric,
                $comparisonStart,
                $comparisonEnd
            );

            if ($comparisonAvg === null || $comparisonAvg == 0) {
                continue;
            }

            $percentChange = abs(($currentWeekAvg - $comparisonAvg) / $comparisonAvg);
            $isSignificant = $percentChange >= self::CHANGE_THRESHOLD;

            if ($isSignificant) {
                $direction = $currentWeekAvg > $comparisonAvg ? 'up' : 'down';
                $type = "trend_{$direction}_weekly";

                // Check if we already have an unacknowledged trend of this type
                $existing = MetricTrend::where('metric_statistic_id', $metric->id)
                    ->where('type', $type)
                    ->whereNull('acknowledged_at')
                    ->where('start_date', '>=', $currentWeekStart->toDateString())
                    ->first();

                if (! $existing) {
                    MetricTrend::create([
                        'metric_statistic_id' => $metric->id,
                        'type' => $type,
                        'detected_at' => now(),
                        'start_date' => $currentWeekStart->toDateString(),
                        'end_date' => $currentWeekEnd->toDateString(),
                        'baseline_value' => $comparisonAvg,
                        'current_value' => $currentWeekAvg,
                        'deviation' => $percentChange,
                        'significance_score' => min($percentChange / self::CHANGE_THRESHOLD, 1.0),
                        'metadata' => [
                            'comparison_weeks' => $weeks,
                            'comparison_start' => $comparisonStart->toDateString(),
                            'comparison_end' => $comparisonEnd->toDateString(),
                        ],
                    ]);

                    Log::info('Detected weekly trend', [
                        'metric_id' => $metric->id,
                        'type' => $type,
                        'weeks_compared' => $weeks,
                        'percent_change' => $percentChange * 100,
                    ]);
                }

                // Only create one weekly trend per direction
                break;
            }
        }
    }

    /**
     * Detect monthly trends
     */
    protected function detectMonthlyTrends(MetricStatistic $metric): void
    {
        $currentMonthStart = now()->startOfMonth();
        $currentMonthEnd = now()->endOfMonth();

        // Get current month average
        $currentMonthAvg = $this->getAverageForPeriod(
            $metric,
            $currentMonthStart,
            $currentMonthEnd
        );

        if ($currentMonthAvg === null) {
            return;
        }

        // Compare against previous 3, 6, and 12 months
        foreach ([3, 6, 12] as $months) {
            $comparisonStart = $currentMonthStart->copy()->subMonths($months);
            $comparisonEnd = $currentMonthStart->copy()->subDay();

            $comparisonAvg = $this->getAverageForPeriod(
                $metric,
                $comparisonStart,
                $comparisonEnd
            );

            if ($comparisonAvg === null || $comparisonAvg == 0) {
                continue;
            }

            $percentChange = abs(($currentMonthAvg - $comparisonAvg) / $comparisonAvg);
            $isSignificant = $percentChange >= self::CHANGE_THRESHOLD;

            if ($isSignificant) {
                $direction = $currentMonthAvg > $comparisonAvg ? 'up' : 'down';
                $type = "trend_{$direction}_monthly";

                // Check if we already have an unacknowledged trend of this type
                $existing = MetricTrend::where('metric_statistic_id', $metric->id)
                    ->where('type', $type)
                    ->whereNull('acknowledged_at')
                    ->where('start_date', '>=', $currentMonthStart->toDateString())
                    ->first();

                if (! $existing) {
                    MetricTrend::create([
                        'metric_statistic_id' => $metric->id,
                        'type' => $type,
                        'detected_at' => now(),
                        'start_date' => $currentMonthStart->toDateString(),
                        'end_date' => $currentMonthEnd->toDateString(),
                        'baseline_value' => $comparisonAvg,
                        'current_value' => $currentMonthAvg,
                        'deviation' => $percentChange,
                        'significance_score' => min($percentChange / self::CHANGE_THRESHOLD, 1.0),
                        'metadata' => [
                            'comparison_months' => $months,
                            'comparison_start' => $comparisonStart->toDateString(),
                            'comparison_end' => $comparisonEnd->toDateString(),
                        ],
                    ]);

                    Log::info('Detected monthly trend', [
                        'metric_id' => $metric->id,
                        'type' => $type,
                        'months_compared' => $months,
                        'percent_change' => $percentChange * 100,
                    ]);
                }

                // Only create one monthly trend per direction
                break;
            }
        }
    }

    /**
     * Detect quarterly trends
     */
    protected function detectQuarterlyTrends(MetricStatistic $metric): void
    {
        $currentQuarterStart = now()->startOfQuarter();
        $currentQuarterEnd = now()->endOfQuarter();

        // Get current quarter average
        $currentQuarterAvg = $this->getAverageForPeriod(
            $metric,
            $currentQuarterStart,
            $currentQuarterEnd
        );

        if ($currentQuarterAvg === null) {
            return;
        }

        // Compare against previous 2 and 4 quarters
        foreach ([2, 4] as $quarters) {
            $comparisonStart = $currentQuarterStart->copy()->subQuarters($quarters);
            $comparisonEnd = $currentQuarterStart->copy()->subDay();

            $comparisonAvg = $this->getAverageForPeriod(
                $metric,
                $comparisonStart,
                $comparisonEnd
            );

            if ($comparisonAvg === null || $comparisonAvg == 0) {
                continue;
            }

            $percentChange = abs(($currentQuarterAvg - $comparisonAvg) / $comparisonAvg);
            $isSignificant = $percentChange >= self::CHANGE_THRESHOLD;

            if ($isSignificant) {
                $direction = $currentQuarterAvg > $comparisonAvg ? 'up' : 'down';
                $type = "trend_{$direction}_quarterly";

                // Check if we already have an unacknowledged trend of this type
                $existing = MetricTrend::where('metric_statistic_id', $metric->id)
                    ->where('type', $type)
                    ->whereNull('acknowledged_at')
                    ->where('start_date', '>=', $currentQuarterStart->toDateString())
                    ->first();

                if (! $existing) {
                    MetricTrend::create([
                        'metric_statistic_id' => $metric->id,
                        'type' => $type,
                        'detected_at' => now(),
                        'start_date' => $currentQuarterStart->toDateString(),
                        'end_date' => $currentQuarterEnd->toDateString(),
                        'baseline_value' => $comparisonAvg,
                        'current_value' => $currentQuarterAvg,
                        'deviation' => $percentChange,
                        'significance_score' => min($percentChange / self::CHANGE_THRESHOLD, 1.0),
                        'metadata' => [
                            'comparison_quarters' => $quarters,
                            'comparison_start' => $comparisonStart->toDateString(),
                            'comparison_end' => $comparisonEnd->toDateString(),
                        ],
                    ]);

                    Log::info('Detected quarterly trend', [
                        'metric_id' => $metric->id,
                        'type' => $type,
                        'quarters_compared' => $quarters,
                        'percent_change' => $percentChange * 100,
                    ]);
                }

                // Only create one quarterly trend per direction
                break;
            }
        }
    }

    /**
     * Get average value for a specific time period
     */
    protected function getAverageForPeriod(MetricStatistic $metric, $startDate, $endDate): ?float
    {
        // Events don't have a user_id column; filter via the integration's user_id
        $events = Event::whereHas('integration', function ($q) use ($metric) {
            $q->where('user_id', $metric->user_id)
                ->whereNull('deleted_at');
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

        $values = $events->map(fn ($event) => $event->getFormattedValueAttribute())->filter();

        if ($values->isEmpty()) {
            return null;
        }

        return $values->average();
    }
}
