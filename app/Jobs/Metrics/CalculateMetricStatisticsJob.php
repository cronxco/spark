<?php

namespace App\Jobs\Metrics;

use App\Models\Event;
use App\Models\MetricStatistic;
use App\Models\User;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\TransactionContext;

class CalculateMetricStatisticsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes

    public $tries = 2;

    public $backoff = [120, 300];

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $hub = SentrySdk::getCurrentHub();
        $txContext = new TransactionContext;
        $txContext->setName('job.metrics:calculate_statistics');
        $txContext->setOp('job');
        $transaction = $hub->startTransaction($txContext);
        $hub->setSpan($transaction);

        try {
            Log::info('Starting metric statistics calculation');

            // Get all unique metric combinations that need calculation
            $metricsToCalculate = $this->getMetricsNeedingCalculation();

            Log::info('Found metrics to calculate', ['count' => $metricsToCalculate->count()]);

            foreach ($metricsToCalculate as $metricData) {
                $this->calculateMetricStatistics(
                    $metricData->user_id,
                    $metricData->service,
                    $metricData->action,
                    $metricData->value_unit
                );
            }

            Log::info('Completed metric statistics calculation');
            $transaction->setStatus(SpanStatus::ok());
        } catch (Exception $e) {
            Log::error('Failed metric statistics calculation', [
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
     * Get all metrics that need calculation
     */
    protected function getMetricsNeedingCalculation()
    {
        // Get all unique user/service/action/unit combinations by joining integrations
        // Events do not have a user_id column — user is owned by the integration
        return DB::table('events')
            ->join('integrations', 'events.integration_id', '=', 'integrations.id')
            ->select('integrations.user_id as user_id', 'events.service', 'events.action', 'events.value_unit')
            ->whereNull('events.deleted_at')
            ->whereNull('integrations.deleted_at')
            ->whereNotNull('events.value')
            ->whereNotNull('events.value_unit')
            ->groupBy('integrations.user_id', 'events.service', 'events.action', 'events.value_unit')
            ->having(DB::raw('COUNT(*)'), '>=', 10) // At least 10 events
            ->get()
            ->filter(function ($metricData) {
                // Check if user has this metric disabled
                $user = User::find($metricData->user_id);
                if (! $user) {
                    return false;
                }

                $identifier = "{$metricData->service}.{$metricData->action}.{$metricData->value_unit}";
                if ($this->isMetricDisabled($user, $identifier)) {
                    return false;
                }

                // Check if needs recalculation
                $statistic = MetricStatistic::where('user_id', $metricData->user_id)
                    ->where('service', $metricData->service)
                    ->where('action', $metricData->action)
                    ->where('value_unit', $metricData->value_unit)
                    ->first();

                // Calculate if never calculated or hasn't been updated in an hour
                return ! $statistic
                    || $statistic->last_calculated_at === null
                    || $statistic->last_calculated_at < now()->subHour();
            });
    }

    /**
     * Calculate statistics for a specific metric
     */
    protected function calculateMetricStatistics(string $userId, string $service, string $action, string $valueUnit): void
    {
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
            Log::info('Insufficient events for metric', [
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
            Log::info('Insufficient time range for metric', [
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

        Log::info('Calculated metric statistics', [
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
    protected function isMetricDisabled(User $user, string $identifier): bool
    {
        $settings = $user->settings ?? [];
        $metricTracking = $settings['metric_tracking'] ?? [];
        $disabledMetrics = $metricTracking['disabled_metrics'] ?? [];

        return in_array($identifier, $disabledMetrics);
    }
}
