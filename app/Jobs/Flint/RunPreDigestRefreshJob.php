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
use Sentry\Severity;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\TransactionContext;

class RunPreDigestRefreshJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600; // 10 minutes

    public function __construct(
        public User $user,
        public string $scheduleTime
    ) {}

    public function failed(?Exception $exception = null): void
    {
        Log::error('[Flint] [PRE-DIGEST] Job failed without exception being caught', [
            'user_id' => $this->user->id,
            'schedule_time' => $this->scheduleTime,
            'exception_provided' => $exception !== null,
            'exception_class' => $exception ? get_class($exception) : null,
            'exception_message' => $exception?->getMessage(),
            'exception_code' => $exception?->getCode(),
            'exception_file' => $exception?->getFile(),
            'exception_line' => $exception?->getLine(),
            'stack_trace' => $exception?->getTraceAsString(),
            'attempts' => $this->attempts(),
            'timeout' => $this->timeout,
        ]);

        // Log to Sentry if exception provided
        if ($exception) {
            \Sentry\captureException($exception);
        } else {
            \Sentry\captureMessage('RunPreDigestRefreshJob failed without exception', Severity::error());
        }
    }

    public function handle(AgentOrchestrationService $orchestration): void
    {
        $jobId = uniqid('predigest_', true);

        Log::info('[Flint] [PRE-DIGEST] Job starting', [
            'job_id' => $jobId,
            'user_id' => $this->user->id,
            'user_email' => $this->user->email,
            'schedule_time' => $this->scheduleTime,
            'attempt' => $this->attempts(),
            'timeout' => $this->timeout,
        ]);

        $transactionContext = new TransactionContext;
        $transactionContext->setName('flint.pre_digest_refresh');
        $transactionContext->setOp('job');
        $transaction = \Sentry\startTransaction($transactionContext);

        SentrySdk::getCurrentHub()->setSpan($transaction);

        // Set user context for Sentry
        \Sentry\configureScope(function (\Sentry\State\Scope $scope) {
            $scope->setUser([
                'id' => $this->user->id,
                'email' => $this->user->email,
            ]);
            $scope->setTag('job_type', 'pre_digest_refresh');
            $scope->setTag('flint_mode', 'pre_digest');
        });

        try {
            Log::info('[Flint] [PRE-DIGEST] Sentry transaction initialized', [
                'job_id' => $jobId,
                'user_id' => $this->user->id,
                'transaction_id' => $transaction->getTraceId(),
            ]);

            Log::info('[PRE-DIGEST] Starting orchestration service', [
                'job_id' => $jobId,
                'user_id' => $this->user->id,
                'schedule_time' => $this->scheduleTime,
            ]);

            $startTime = microtime(true);
            $results = $orchestration->runPreDigestRefresh($this->user);
            $duration = microtime(true) - $startTime;

            Log::info('[Flint] [PRE-DIGEST] Orchestration completed', [
                'job_id' => $jobId,
                'user_id' => $this->user->id,
                'duration_seconds' => round($duration, 2),
                'domains_analyzed' => array_keys($results),
                'results_summary' => array_map(function ($r) {
                    if ($r === null) {
                        return 'null';
                    }
                    if (is_array($r)) {
                        return 'array(' . count($r) . ' items)';
                    }

                    return gettype($r);
                }, $results),
            ]);

            $transaction->setData([
                'job_id' => $jobId,
                'user_id' => $this->user->id,
                'schedule_time' => $this->scheduleTime,
                'domains_analyzed' => array_keys($results),
                'duration_seconds' => round($duration, 2),
                'success' => true,
            ]);

            Log::info('[Flint] [PRE-DIGEST] Finishing Sentry transaction', [
                'job_id' => $jobId,
                'user_id' => $this->user->id,
            ]);

            $transaction->finish();

            Log::info('[Flint] [PRE-DIGEST] Dispatching digest generation job', [
                'job_id' => $jobId,
                'user_id' => $this->user->id,
                'schedule_time' => $this->scheduleTime,
            ]);

            // Chain to digest generation job immediately after agents complete
            dispatch(new RunDigestGenerationJob($this->user, $this->scheduleTime))
                ->onQueue('flint');

            Log::info('[Flint] [PRE-DIGEST] Job completed successfully', [
                'job_id' => $jobId,
                'user_id' => $this->user->id,
                'total_duration_seconds' => round(microtime(true) - $startTime, 2),
            ]);

        } catch (Exception $e) {
            Log::error('[Flint] [PRE-DIGEST] Exception caught', [
                'job_id' => $jobId,
                'user_id' => $this->user->id,
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
                'exception_code' => $e->getCode(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
            ]);

            $transaction->setStatus(SpanStatus::internalError());
            $transaction->finish();

            \Sentry\captureException($e);

            Log::error('[Flint] [PRE-DIGEST] Job failed', [
                'job_id' => $jobId,
                'user_id' => $this->user->id,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);

            throw $e;
        }
    }
}
