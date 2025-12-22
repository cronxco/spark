<?php

namespace App\Jobs\Flint;

use App\Models\User;
use App\Services\AgentOrchestrationService;
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

class RunContinuousBackgroundAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300; // 5 minutes

    public function __construct(public User $user) {}

    public function failed(?Throwable $exception): void
    {
        // Log the failure with full context
        Log::error('RunContinuousBackgroundAnalysisJob failed before execution', [
            'user_id' => $this->user->id ?? 'unknown',
            'exception_class' => $exception ? get_class($exception) : 'unknown',
            'exception_message' => $exception?->getMessage(),
            'exception_code' => $exception?->getCode(),
            'exception_file' => $exception?->getFile(),
            'exception_line' => $exception?->getLine(),
            'stack_trace' => $exception?->getTraceAsString(),
        ]);

        // Capture in Sentry with context
        if ($exception) {
            \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($exception) {
                $scope->setContext('job_failure', [
                    'stage' => 'pre_execution',
                    'user_id' => $this->user->id ?? 'unknown',
                    'job_class' => self::class,
                ]);

                $scope->setContext('exception_details', [
                    'class' => get_class($exception),
                    'message' => $exception->getMessage(),
                    'code' => $exception->getCode(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'trace' => $exception->getTraceAsString(),
                ]);

                $scope->setTag('job_failed_stage', 'pre_execution');
                $scope->setTag('job_type', 'continuous_background_analysis');

                \Sentry\captureException($exception);
            });
        }
    }

    public function handle(AgentOrchestrationService $orchestration): void
    {
        $transactionContext = new TransactionContext;
        $transactionContext->setName('flint.continuous_background_analysis');
        $transactionContext->setOp('job');
        $transaction = \Sentry\startTransaction($transactionContext);

        SentrySdk::getCurrentHub()->setSpan($transaction);

        // Set user context for Sentry
        \Sentry\configureScope(function (\Sentry\State\Scope $scope) {
            $scope->setUser([
                'id' => $this->user->id,
                'email' => $this->user->email,
            ]);
            $scope->setTag('job_type', 'continuous_background_analysis');
            $scope->setTag('flint_mode', 'continuous');
        });

        try {
            Log::info('Running continuous background analysis', [
                'user_id' => $this->user->id,
                'attempt' => $this->attempts(),
            ]);

            $results = $orchestration->runContinuousBackgroundAnalysis($this->user);

            $transaction->setData([
                'user_id' => $this->user->id,
                'domains_analyzed' => array_keys($results),
                'success' => true,
            ]);

            $transaction->finish();

            Log::info('Continuous background analysis completed', [
                'user_id' => $this->user->id,
                'results' => array_map(fn ($r) => $r !== null, $results),
            ]);
        } catch (Throwable $e) {
            $transaction->setStatus(SpanStatus::internalError());
            $transaction->setData([
                'error_message' => $e->getMessage(),
                'error_class' => get_class($e),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
            ]);
            $transaction->finish();

            // Capture exception with full context in Sentry
            \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($e) {
                $scope->setContext('job', [
                    'attempt' => $this->attempts(),
                    'max_tries' => $this->tries,
                    'timeout' => $this->timeout,
                    'queue' => $this->queue,
                ]);

                $scope->setContext('user', [
                    'id' => $this->user->id,
                    'email' => $this->user->email,
                ]);

                $scope->setContext('exception_details', [
                    'class' => get_class($e),
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);

                \Sentry\captureException($e);
            });

            Log::error('Continuous background analysis failed', [
                'user_id' => $this->user->id,
                'attempt' => $this->attempts(),
                'max_tries' => $this->tries,
                'error_class' => get_class($e),
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
