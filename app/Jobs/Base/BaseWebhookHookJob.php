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
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\TransactionContext;
use Throwable;

abstract class BaseWebhookHookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 60; // 1 minute for webhook processing

    public $tries = 3;

    public $backoff = [30, 120, 300]; // Retry after 30s, 2min, 5min

    protected Integration $integration;

    protected array $webhookPayload;

    protected array $headers;

    protected string $serviceName;

    /**
     * Create a new job instance.
     */
    public function __construct(array $webhookPayload, array $headers, Integration $integration)
    {
        $this->webhookPayload = $webhookPayload;
        $this->headers = $headers;
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
        $txContext->setName('job.webhook:' . $this->serviceName . ':' . $this->getJobType());
        $txContext->setOp('job');
        $transaction = $hub->startTransaction($txContext);
        $hub->setSpan($transaction);

        try {
            Log::info("Processing {$this->getJobType()} webhook for integration {$this->integration->id} ({$this->serviceName})");

            // Validate webhook signature if required
            $this->validateWebhook();

            // Split the webhook data into processing chunks
            $processingData = $this->splitWebhookData();

            // Dispatch processing jobs for each chunk
            $this->dispatchProcessingJobs($processingData);

            Log::info("Completed {$this->getJobType()} webhook processing for integration {$this->integration->id} ({$this->serviceName})");
            $transaction->setStatus(SpanStatus::ok());

        } catch (Exception $e) {
            Log::error("Failed {$this->getJobType()} webhook processing for integration {$this->integration->id} ({$this->serviceName})", [
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
        Log::error("{$this->getJobType()} webhook job failed permanently for integration {$this->integration->id} ({$this->serviceName})", [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }

    /**
     * Get the unique job identifier for idempotency.
     */
    public function uniqueId(): string
    {
        return $this->serviceName . '_webhook_' . $this->integration->id . '_' . md5(serialize($this->webhookPayload));
    }

    /**
     * Get the service name for this job (e.g., 'apple_health', 'slack').
     */
    abstract protected function getServiceName(): string;

    /**
     * Get the job type for logging (e.g., 'webhook', 'event').
     */
    abstract protected function getJobType(): string;

    /**
     * Validate the webhook signature/authorization.
     */
    abstract protected function validateWebhook(): void;

    /**
     * Split the webhook payload into processing chunks.
     */
    abstract protected function splitWebhookData(): array;

    /**
     * Dispatch processing jobs for each chunk of data.
     */
    abstract protected function dispatchProcessingJobs(array $processingData): void;

    /**
     * Log webhook payload for debugging.
     */
    protected function logWebhookPayload(): void
    {
        log_integration_webhook(
            $this->serviceName,
            $this->integration->id,
            $this->sanitizeData($this->webhookPayload),
            $this->sanitizeHeaders($this->headers),
            true // Use per-instance logging
        );
    }

    /**
     * Sanitize headers for logging (remove sensitive data).
     */
    protected function sanitizeHeaders(array $headers): array
    {
        $sensitiveHeaders = ['authorization', 'x-api-key', 'x-auth-token', 'x-signature', 'x-hub-signature'];
        $sanitized = [];

        foreach ($headers as $key => $value) {
            $lowerKey = strtolower($key);
            if (in_array($lowerKey, $sensitiveHeaders)) {
                $sanitized[$key] = ['[REDACTED]'];
            } elseif (is_array($value)) {
                $sanitized[$key] = $value; // Headers are already arrays from Laravel
            } else {
                $sanitized[$key] = [$value];
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize data for logging (remove sensitive data).
     */
    protected function sanitizeData(array $data): array
    {
        $sensitiveKeys = ['password', 'token', 'secret', 'key', 'auth', 'signature'];
        $sanitized = [];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower($key);
            if (in_array($lowerKey, $sensitiveKeys)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeData($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
