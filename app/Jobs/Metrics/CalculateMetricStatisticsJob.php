<?php

namespace App\Jobs\Metrics;

use App\Models\Event;
use App\Models\MetricStatistic;
use App\Models\User;
use App\Services\Metrics\BaselineService;
use App\Services\Metrics\MetricCatalogue;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\TransactionContext;

class CalculateMetricStatisticsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes

    public $tries = 2;

    public $backoff = [120, 300];

    /**
     * @param  string|null  $userId  Limit the run to one user. A user-triggered
     *                               run must pass this; null is the all-tenant run.
     * @param  bool  $judgedOnly  Only relearn the metrics that are judged (the nightly run)
     */
    public function __construct(public ?string $userId = null, public bool $judgedOnly = false) {}

    /**
     * One pending run per user (or one all-tenant run) at a time, so repeated
     * clicks don't stack recalculation work on the queue.
     */
    public function uniqueId(): string
    {
        return $this->userId ?? 'all';
    }

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
                    $metricData->value_unit,
                    $metricData->domain ?? 'health'
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
            ->select('integrations.user_id as user_id', $eventsTable . '.service', $eventsTable . '.action', $eventsTable . '.value_unit', $eventsTable . '.domain')
            ->whereNull($eventsTable . '.deleted_at')
            ->whereNull('integrations.deleted_at')
            ->when($this->userId, fn ($query) => $query->where('integrations.user_id', $this->userId))
            ->where(fn ($query) => $query
                ->whereNull($eventsTable . '.event_metadata->internal')
                ->orWhere($eventsTable . '.event_metadata->internal', false))
            ->whereNotNull($eventsTable . '.value')
            ->whereNotNull($eventsTable . '.value_unit')
            ->groupBy('integrations.user_id', $eventsTable . '.service', $eventsTable . '.action', $eventsTable . '.value_unit', $eventsTable . '.domain')
            ->having(DB::raw('COUNT(*)'), '>=', 10) // At least 10 events
            ->get()
            ->filter(function ($metricData) {
                // The all-tenant nightly run relearns the metrics that are judged;
                // the rest are refreshed as their events arrive, or on request.
                if ($this->judgedOnly && ! app(MetricCatalogue::class)->isJudgeable($metricData->service, $metricData->action)) {
                    return false;
                }

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
     * Recompute the baselines for a specific metric. The maths lives in
     * BaselineService, which the task pipeline shares.
     */
    protected function calculateMetricStatistics(string $userId, string $service, string $action, string $valueUnit, string $domain = 'health'): void
    {
        $statistic = MetricStatistic::firstOrNew([
            'user_id' => $userId,
            'service' => $service,
            'action' => $action,
            'value_unit' => $valueUnit,
        ]);

        app(BaselineService::class)->recompute($statistic);
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
