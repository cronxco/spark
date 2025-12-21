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

class RunDigestGenerationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600; // 10 minutes

    public function __construct(
        public User $user,
        public string $period = 'morning'
    ) {}

    public function handle(AgentOrchestrationService $orchestration): void
    {
        $transactionContext = new TransactionContext;
        $transactionContext->setName('flint.digest_generation');
        $transactionContext->setOp('job');
        $transaction = \Sentry\startTransaction($transactionContext);

        SentrySdk::getCurrentHub()->setSpan($transaction);

        // Set user context for Sentry
        \Sentry\configureScope(function (\Sentry\State\Scope $scope) {
            $scope->setUser([
                'id' => $this->user->id,
                'email' => $this->user->email,
            ]);
            $scope->setTag('job_type', 'digest_generation');
            $scope->setTag('flint_mode', 'digest');
        });

        try {
            Log::info('Running digest generation', [
                'user_id' => $this->user->id,
                'period' => $this->period,
            ]);

            $digestBlockId = $orchestration->runDigestGeneration($this->user, $this->period);

            $transaction->setData([
                'user_id' => $this->user->id,
                'digest_block_id' => $digestBlockId,
                'success' => true,
            ]);

            $transaction->finish();

            Log::info('Digest generation completed', [
                'user_id' => $this->user->id,
                'digest_block_id' => $digestBlockId,
            ]);
        } catch (Exception $e) {
            $transaction->setStatus(SpanStatus::internalError());
            $transaction->finish();

            \Sentry\captureException($e);

            Log::error('Digest generation failed', [
                'user_id' => $this->user->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
