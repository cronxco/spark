<?php

namespace App\Jobs\Base;

use App\Jobs\Concerns\EnhancedIdempotency;
use App\Models\Integration;
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

abstract class BaseEffectJob implements ShouldQueue
{
    use Dispatchable, EnhancedIdempotency, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes for effect execution

    public $tries = 3;

    public $backoff = [60, 300, 900]; // Retry after 1, 5, 15 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected Integration $integration,
        protected array $parameters = []
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): array
    {
        $hub = SentrySdk::getCurrentHub();
        $txContext = new TransactionContext;
        $txContext->setName('job.effect:'.$this->integration->service.':'.$this->getEffectType());
        $txContext->setOp('job');
        $transaction = $hub->startTransaction($txContext);
        $hub->setSpan($transaction);

        try {
            // Log start to all levels (instance, group, user)
            log_hierarchical($this->integration, 'info', "Starting {$this->getEffectType()} effect", [
                'integration_id' => $this->integration->id,
                'integration_name' => $this->integration->name,
                'service' => $this->integration->service,
                'effect_type' => $this->getEffectType(),
                'parameters' => $this->parameters,
            ]);

            // Execute the effect
            $result = $this->execute();

            // Log completion to all levels
            log_hierarchical($this->integration, 'info', "Completed {$this->getEffectType()} effect", [
                'integration_id' => $this->integration->id,
                'integration_name' => $this->integration->name,
                'service' => $this->integration->service,
                'effect_type' => $this->getEffectType(),
                'success' => $result['success'] ?? false,
                'message' => $result['message'] ?? null,
            ]);

            $transaction->setStatus(SpanStatus::ok());

            return $result;
        } catch (Throwable $e) {
            // Log errors to all levels
            log_hierarchical($this->integration, 'error', "Failed {$this->getEffectType()} effect", [
                'integration_id' => $this->integration->id,
                'integration_name' => $this->integration->name,
                'service' => $this->integration->service,
                'effect_type' => $this->getEffectType(),
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
        // Log permanent failure to all levels
        log_hierarchical($this->integration, 'critical', "{$this->getEffectType()} effect job failed permanently", [
            'integration_id' => $this->integration->id,
            'integration_name' => $this->integration->name,
            'service' => $this->integration->service,
            'effect_type' => $this->getEffectType(),
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }

    /**
     * Get the effect type for logging (e.g., 'analyze_progression', 'update_routine').
     */
    protected function getEffectType(): string
    {
        // Extract effect type from class name (e.g., HevyAnalyzeProgressionEffect -> analyze_progression)
        $className = class_basename(static::class);
        $effectName = str_replace('Effect', '', $className);
        $effectName = preg_replace('/(?<!^)[A-Z]/', '_$0', $effectName);

        return strtolower($effectName);
    }

    /**
     * Execute the effect and return result.
     *
     * @return array{success: bool, message: string, data: array}
     */
    abstract protected function execute(): array;
}
