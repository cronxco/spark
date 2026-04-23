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
use Sentry\State\Scope;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\TransactionContext;

class RunDigestGenerationJob implements ShouldQueue
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
        $transactionContext->setName('flint.digest_generation');
        $transactionContext->setOp('job');
        $transaction = \Sentry\startTransaction($transactionContext);

        SentrySdk::getCurrentHub()->setSpan($transaction);

        // Set user context for Sentry
        \Sentry\configureScope(function (Scope $scope) {
            $scope->setUser([
                'id' => $this->user->id,
                'email' => $this->user->email,
            ]);
            $scope->setTag('job_type', 'digest_generation');
            $scope->setTag('flint_mode', 'digest');
        });

        try {
            $period = $this->getDigestPeriod($this->scheduleTime);

            Log::info('Running digest generation', [
                'user_id' => $this->user->id,
                'schedule_time' => $this->scheduleTime,
                'period' => $period,
            ]);

            $digestBlockId = $orchestration->runDigestGeneration($this->user, $period);

            $transaction->setData([
                'user_id' => $this->user->id,
                'schedule_time' => $this->scheduleTime,
                'period' => $period,
                'digest_block_id' => $digestBlockId,
                'success' => true,
            ]);

            $transaction->finish();

            Log::info('Digest generation completed', [
                'user_id' => $this->user->id,
                'schedule_time' => $this->scheduleTime,
                'period' => $period,
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

    protected function getDigestPeriod(string $scheduleTime): string
    {
        $hour = (int) substr($scheduleTime, 0, 2);

        if ($hour < 12) {
            return 'morning';
        } elseif ($hour < 17) {
            return 'afternoon';
        } else {
            return 'evening';
        }
    }
}
