<?php

namespace App\Jobs\Base;

use App\Jobs\Concerns\EnhancedIdempotency;
use App\Models\Integration;
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
            Log::info("Starting {$this->getJobType()} fetch for integration {$this->integration->id} ({$this->serviceName})");

            // Fetch the raw data
            $rawData = $this->fetchData();

            // Dispatch processing jobs with the fetched data
            $this->dispatchProcessingJobs($rawData);

            Log::info("Completed {$this->getJobType()} fetch for integration {$this->integration->id} ({$this->serviceName})");
            $transaction->setStatus(SpanStatus::ok());

        } catch (Exception $e) {
            Log::error("Failed {$this->getJobType()} fetch for integration {$this->integration->id} ({$this->serviceName})", [
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
        Log::error("{$this->getJobType()} fetch job failed permanently for integration {$this->integration->id} ({$this->serviceName})", [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Mark integration as failed so it can be retried in the future
        $this->integration->markAsFailed();
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
