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

class RunPatternDetectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 900; // 15 minutes for 90-day analysis

    public function __construct(public User $user) {}

    public function handle(AgentOrchestrationService $orchestration): void
    {
        $transactionContext = new TransactionContext;
        $transactionContext->setName('flint.pattern_detection');
        $transactionContext->setOp('job');
        $transaction = \Sentry\startTransaction($transactionContext);

        SentrySdk::getCurrentHub()->setSpan($transaction);

        // Set user context for Sentry
        \Sentry\configureScope(function (\Sentry\State\Scope $scope) {
            $scope->setUser([
                'id' => $this->user->id,
            ]);
            $scope->setTag('job_type', 'pattern_detection');
            $scope->setTag('flint_mode', 'pattern_detection');
        });

        try {
            Log::info('Running pattern detection', [
                'user_id' => $this->user->id,
            ]);

            $patterns = $orchestration->runPatternDetection($this->user);

            $transaction->setData([
                'user_id' => $this->user->id,
                'patterns_detected' => count($patterns),
                'success' => true,
            ]);

            $transaction->finish();

            Log::info('Pattern detection completed', [
                'user_id' => $this->user->id,
                'patterns_detected' => count($patterns),
            ]);
        } catch (Exception $e) {
            $transaction->setStatus(SpanStatus::internalError());
            $transaction->finish();

            \Sentry\captureException($e);

            Log::error('Pattern detection failed', [
                'user_id' => $this->user->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
