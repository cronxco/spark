<?php

namespace App\Jobs\Base;

use App\Models\Integration;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\TransactionContext;
use Throwable;

abstract class BaseInitializationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes for initialization

    public $tries = 1; // Usually only run once

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
        $txContext->setName('job.init:' . $this->serviceName . ':' . $this->getJobType());
        $txContext->setOp('job');
        $transaction = $hub->startTransaction($txContext);
        $hub->setSpan($transaction);

        try {
            Log::info("Starting initialization for integration {$this->integration->id} ({$this->serviceName})");

            $span = $transaction->startChild((new SpanContext)->setOp('integration.initialize')->setDescription($this->serviceName));
            $this->initialize();
            $span->finish();

            // Mark as successfully initialized
            $this->integration->update(['last_successful_at' => now()]);

            Log::info("Completed initialization for integration {$this->integration->id} ({$this->serviceName})");
            $transaction->setStatus(SpanStatus::ok());

        } catch (Exception $e) {
            Log::error("Failed initialization for integration {$this->integration->id} ({$this->serviceName})", [
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
        Log::error("Initialization job failed permanently for integration {$this->integration->id} ({$this->serviceName})", [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Mark as failed so it can be retried manually
        $this->integration->markAsFailed();
    }

    /**
     * Get the unique job identifier for idempotency.
     */
    public function uniqueId(): string
    {
        return $this->serviceName . '_init_' . $this->integration->id . '_' . $this->getJobType();
    }

    /**
     * Get the service name for this job (e.g., 'monzo', 'oura').
     */
    abstract protected function getServiceName(): string;

    /**
     * Get the job type for logging (e.g., 'historical', 'migration').
     */
    abstract protected function getJobType(): string;

    /**
     * Perform the initialization work.
     */
    abstract protected function initialize(): void;
}
