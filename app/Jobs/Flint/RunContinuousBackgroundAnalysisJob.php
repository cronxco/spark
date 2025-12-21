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

class RunContinuousBackgroundAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300; // 5 minutes

    public function __construct(public User $user) {}

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
        } catch (Exception $e) {
            $transaction->setStatus(SpanStatus::internalError());
            $transaction->finish();

            \Sentry\captureException($e);

            Log::error('Continuous background analysis failed', [
                'user_id' => $this->user->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
