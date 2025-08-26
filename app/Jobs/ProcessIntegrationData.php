<?php

namespace App\Jobs;

use App\Integrations\PluginRegistry;
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

class ProcessIntegrationData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes

    public $tries = 3;

    public $backoff = [60, 300, 600]; // Retry after 1, 5, 10 minutes

    protected Integration $integration;

    /**
     * Create a new job instance.
     */
    public function __construct(Integration $integration)
    {
        $this->integration = $integration;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $hub = SentrySdk::getCurrentHub();
        $txContext = new TransactionContext;
        $txContext->setName('job.process_integration: ' . $this->integration->service);
        $txContext->setOp('job');
        $transaction = $hub->startTransaction($txContext);
        $hub->setSpan($transaction);

        try {
            $pluginClass = PluginRegistry::getPlugin($this->integration->service);
            if (! $pluginClass) {
                throw new Exception("Plugin for service '{$this->integration->service}' not found");
            }

            $plugin = new $pluginClass;

            Log::info("Processing data for integration {$this->integration->id} ({$this->integration->service})");

            // Mark as triggered before processing
            $this->integration->markAsTriggered();

            $span = $transaction->startChild((new SpanContext)->setOp('integration.fetch')->setDescription($this->integration->service));
            $plugin->fetchData($this->integration);
            $span->finish();

            // Mark as successfully updated after processing
            $this->integration->markAsSuccessfullyUpdated();

            Log::info("Successfully processed data for integration {$this->integration->id} ({$this->integration->service})");
            $transaction->setStatus(SpanStatus::ok());

        } catch (Exception $e) {
            Log::error("Failed to process data for integration {$this->integration->id} ({$this->integration->service})", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            \Sentry\captureException($e);

            // Mark as failed so it can be retried
            $this->integration->markAsFailed();

            $transaction->setStatus(SpanStatus::internalError());
            throw $e; // Re-throw to trigger retry
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
        Log::error("Integration data processing job failed permanently for integration {$this->integration->id} ({$this->integration->service})", [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
        \Sentry\captureException($exception);

        // Mark as failed so it can be retried in the future
        $this->integration->markAsFailed();
    }
}
