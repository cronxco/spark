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

class RunPreDigestRefreshJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600; // 10 minutes

    public function __construct(
        public User $user,
        public string $scheduleTime
    ) {}

    public function handle(AgentOrchestrationService $orchestration): void
    {
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
            Log::info('Running pre-digest refresh', [
                'user_id' => $this->user->id,
                'schedule_time' => $this->scheduleTime,
            ]);

            $results = $orchestration->runPreDigestRefresh($this->user);

            $transaction->setData([
                'user_id' => $this->user->id,
                'schedule_time' => $this->scheduleTime,
                'domains_analyzed' => array_keys($results),
                'success' => true,
            ]);

            $transaction->finish();

            Log::info('Pre-digest refresh completed', [
                'user_id' => $this->user->id,
                'schedule_time' => $this->scheduleTime,
                'results' => array_map(fn ($r) => $r !== null, $results),
            ]);

            // Chain to digest generation job immediately after agents complete
            dispatch(new RunDigestGenerationJob($this->user, $this->scheduleTime))
                ->onQueue('flint');

        } catch (Exception $e) {
            $transaction->setStatus(SpanStatus::internalError());
            $transaction->finish();

            \Sentry\captureException($e);

            Log::error('Pre-digest refresh failed', [
                'user_id' => $this->user->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
