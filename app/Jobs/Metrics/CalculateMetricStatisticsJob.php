<?php

namespace App\Jobs\Metrics;

use App\Models\Event;
use App\Models\MetricStatistic;
use App\Models\User;
use Carbon\Carbon;
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
        $eventsTable = (new Event)->getTable();

        return DB::table($eventsTable)
            ->join('integrations', $eventsTable . '.integration_id', '=', 'integrations.id')
            ->select('integrations.user_id as user_id', $eventsTable . '.service', $eventsTable . '.action', $eventsTable . '.value_unit')
            ->whereNull($eventsTable . '.deleted_at')
            ->whereNull('integrations.deleted_at')
            ->whereNotNull($eventsTable . '.value')
            ->whereNotNull($eventsTable . '.value_unit')
            ->groupBy('integrations.user_id', $eventsTable . '.service', $eventsTable . '.action', $eventsTable . '.value_unit')
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

                // Check if user has anomaly detection mode set to disabled
                $override = $user->getAnomalyDetectionModeOverride(
                    $metricData->service,
                    $metricData->action,
                    $metricData->value_unit
                );
                if ($override === 'disabled') {
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
     *
     * Aggregates in SQL rather than pulling every historical event into PHP —
     * `events` rows are large (they include a 1536-dim `embeddings` vector and
     * JSON metadata columns), so `SELECT *` on a multi-million-row history
     * dominated database egress and wall-time.
     */
    protected function calculateMetricStatistics(string $userId, string $service, string $action, string $valueUnit): void
    {
        $eventsTable = (new Event)->getTable();

        // Mirrors Event::getFormattedValueAttribute() in SQL: null/0/1 multipliers
        // pass the raw value through, everything else divides.
        $formattedExpr = <<<'SQL'
            CASE
                WHEN e.value_multiplier IS NULL OR e.value_multiplier IN (0, 1)
                    THEN e.value::double precision
                ELSE e.value::double precision / e.value_multiplier
            END
        SQL;

        $row = DB::table($eventsTable . ' as e')
            ->join('integrations as i', 'e.integration_id', '=', 'i.id')
            ->where('i.user_id', $userId)
            ->whereNull('i.deleted_at')
            ->where('e.service', $service)
            ->where('e.action', $action)
            ->where('e.value_unit', $valueUnit)
            ->whereNotNull('e.value')
            ->whereNull('e.deleted_at')
            ->selectRaw(<<<SQL
                COUNT(*) AS total_count,
                MIN(e.time) AS first_event_at,
                MAX(e.time) AS last_event_at,
                COUNT(*) FILTER (WHERE e.value <> 0) AS formatted_count,
                MIN({$formattedExpr}) FILTER (WHERE e.value <> 0) AS min_value,
                MAX({$formattedExpr}) FILTER (WHERE e.value <> 0) AS max_value,
                AVG({$formattedExpr}) FILTER (WHERE e.value <> 0) AS mean_value,
                STDDEV_POP({$formattedExpr}) FILTER (WHERE e.value <> 0) AS stddev_value
            SQL)
            ->first();

        $totalCount = (int) ($row->total_count ?? 0);

        if ($totalCount < 10) {
            Log::info('Insufficient events for metric', [
                'user_id' => $userId,
                'service' => $service,
                'action' => $action,
                'value_unit' => $valueUnit,
                'count' => $totalCount,
            ]);

            return;
        }

        $firstEventAt = $row->first_event_at ? Carbon::parse($row->first_event_at) : null;
        $lastEventAt = $row->last_event_at ? Carbon::parse($row->last_event_at) : null;

        if (! $firstEventAt || ! $lastEventAt) {
            return;
        }

        $daysBetween = $firstEventAt->diffInDays($lastEventAt);

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

        $formattedCount = (int) ($row->formatted_count ?? 0);

        if ($formattedCount === 0 || $row->mean_value === null) {
            return;
        }

        $mean = (float) $row->mean_value;
        $stddev = (float) ($row->stddev_value ?? 0.0);

        MetricStatistic::updateOrCreate(
            [
                'user_id' => $userId,
                'service' => $service,
                'action' => $action,
                'value_unit' => $valueUnit,
            ],
            [
                'event_count' => $formattedCount,
                'first_event_at' => $firstEventAt,
                'last_event_at' => $lastEventAt,
                'min_value' => (float) $row->min_value,
                'max_value' => (float) $row->max_value,
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
            'count' => $formattedCount,
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
