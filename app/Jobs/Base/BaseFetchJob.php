<?php

namespace App\Jobs\Base;

use App\Exceptions\GoCardlessEuaExpiredException;
use App\Jobs\Concerns\EnhancedIdempotency;
use App\Jobs\GoCardless\HandleExpiredEuaJob;
use App\Models\Integration;
use App\Notifications\IntegrationFailed;
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
use Throwable;

abstract class BaseFetchJob implements ShouldQueue
{
    use Dispatchable, EnhancedIdempotency, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120; // 2 minutes for API calls

    public $tries = 3;

    public $backoff = [60, 300, 600]; // Retry after 1, 5, 10 minutes

    protected Integration $integration;

    protected string $serviceName;

    /**
     * Create a new job instance.
     */
    public function __construct(Integration $integration)
    {
        $this->integration = $integration;
        $this->serviceName = $this->getServiceName();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $hub = SentrySdk::getCurrentHub();
        $txContext = new TransactionContext;
        $txContext->setName('job.fetch:' . $this->serviceName . ':' . $this->getJobType());
        $txContext->setOp('job');
        $transaction = $hub->startTransaction($txContext);
        $hub->setSpan($transaction);

        try {
            // Log start to all levels (instance, group, user)
            log_hierarchical($this->integration, 'info', "Starting {$this->getJobType()} fetch", [
                'integration_id' => $this->integration->id,
                'integration_name' => $this->integration->name,
                'service' => $this->serviceName,
                'job_type' => $this->getJobType(),
            ]);

            // Fetch the raw data
            $rawData = $this->fetchData();

            // Dispatch processing jobs with the fetched data
            $this->dispatchProcessingJobs($rawData);

            // Mark the integration as successfully updated
            $this->integration->markAsSuccessfullyUpdated();

            // Log completion to all levels
            log_hierarchical($this->integration, 'info', "Completed {$this->getJobType()} fetch", [
                'integration_id' => $this->integration->id,
                'integration_name' => $this->integration->name,
                'service' => $this->serviceName,
                'job_type' => $this->getJobType(),
            ]);
            $transaction->setStatus(SpanStatus::ok());
        } catch (Exception $e) {
            // Log errors to all levels
            log_hierarchical($this->integration, 'error', "Failed {$this->getJobType()} fetch", [
                'integration_id' => $this->integration->id,
                'integration_name' => $this->integration->name,
                'service' => $this->serviceName,
                'job_type' => $this->getJobType(),
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
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        // Check if this is a GoCardless EUA expiry
        if ($exception instanceof GoCardlessEuaExpiredException) {
            Log::info('BaseFetchJob: Detected GoCardless EUA expiry, dispatching HandleExpiredEuaJob', [
                'integration_id' => $this->integration->id,
                'group_id' => $exception->getGroupId(),
            ]);

            // Dispatch special handling job
            dispatch(new HandleExpiredEuaJob(
                $exception->getGroupId(),
                $exception->getEuaId(),
                $exception->getErrorResponse()
            ));

            // Don't send the normal failure notification - HandleExpiredEuaJob will handle it
            return;
        }

        // Log permanent failure to all levels
        log_hierarchical($this->integration, 'critical', "{$this->getJobType()} fetch job failed permanently", [
            'integration_id' => $this->integration->id,
            'integration_name' => $this->integration->name,
            'service' => $this->serviceName,
            'job_type' => $this->getJobType(),
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Mark integration as failed so it can be retried in the future
        $this->integration->markAsFailed();

        // Notify user of permanent failure
        try {
            $this->integration->user->notify(
                new IntegrationFailed(
                    $this->integration,
                    $exception->getMessage(),
                    [
                        'job_type' => $this->getJobType(),
                        'service' => $this->serviceName,
                        'attempts' => $this->attempts(),
                    ]
                )
            );
        } catch (Exception $e) {
            Log::error('Failed to send IntegrationFailed notification', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get the unique job identifier for idempotency.
     */
    public function uniqueId(): string
    {
        return $this->serviceName . '_' . $this->getJobType() . '_' . $this->integration->id . '_' . now()->toDateString();
    }

    /**
     * Get the service name for this job (e.g., 'monzo', 'oura').
     */
    abstract protected function getServiceName(): string;

    /**
     * Get the job type for logging (e.g., 'accounts', 'transactions').
     */
    abstract protected function getJobType(): string;

    /**
     * Fetch raw data from the external API.
     */
    abstract protected function fetchData(): array;

    /**
     * Dispatch processing jobs with the fetched data.
     */
    abstract protected function dispatchProcessingJobs(array $rawData): void;
}
