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

use function Sentry\captureException;

class ProcessSpotifyData implements ShouldQueue
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
        $txContext->setName('job.process_spotify');
        $txContext->setOp('job');
        $transaction = $hub->startTransaction($txContext);
        $hub->setSpan($transaction);

        try {
            $pluginClass = PluginRegistry::getPlugin('spotify');
            if (! $pluginClass) {
                throw new Exception('Spotify plugin not found');
            }

            $plugin = new $pluginClass;

            Log::info("Processing Spotify data for integration {$this->integration->id}");

            // Mark as triggered before processing
            $this->integration->markAsTriggered();

            $span = $transaction->startChild((new SpanContext)->setOp('integration.fetch')->setDescription('spotify'));
            $plugin->fetchData($this->integration);
            $span->finish();

            // Mark as successfully updated after processing
            $this->integration->markAsSuccessfullyUpdated();

            Log::info("Successfully processed Spotify data for integration {$this->integration->id}");
            $transaction->setStatus(SpanStatus::ok());

        } catch (Exception $e) {
            Log::error("Failed to process Spotify data for integration {$this->integration->id}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            captureException($e);

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
        Log::error("Spotify data processing job failed permanently for integration {$this->integration->id}", [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
        captureException($exception);
    }
}
