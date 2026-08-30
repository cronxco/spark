<?php

namespace App\Jobs\Flint;

use App\Models\Integration;
use App\Models\User;
use App\Services\AgentOrchestrationService;
use App\Services\TaskPipeline\TaskDefinition;
use App\Services\TaskPipeline\TaskExecutionStore;
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

class RunPatternDetectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 900; // 15 minutes for 90-day analysis

    public function __construct(public User $user) {}

    public function handle(AgentOrchestrationService $orchestration, TaskExecutionStore $store): void
    {
        $integration = Integration::where('user_id', $this->user->id)
            ->where('service', 'flint')
            ->first();

        if (! $integration) {
            Log::warning('Pattern detection: no Flint integration found for user, skipping task execution tracking', [
                'user_id' => $this->user->id,
            ]);
        }

        $task = $this->taskDefinition();

        $transactionContext = new TransactionContext;
        $transactionContext->setName('flint.pattern_detection');
        $transactionContext->setOp('job');
        $transaction = \Sentry\startTransaction($transactionContext);

        SentrySdk::getCurrentHub()->setSpan($transaction);

        // Set user context for Sentry
        \Sentry\configureScope(function (Scope $scope) {
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

            if ($integration) {
                $store->recordStatus($integration, $task, 'pending', ['error' => null]);
            }

            $patterns = $orchestration->runPatternDetection($this->user);

            $transaction->setData([
                'user_id' => $this->user->id,
                'patterns_detected' => count($patterns),
                'success' => true,
            ]);

            $transaction->finish();

            if ($integration) {
                $store->recordStatus($integration, $task, 'success', [
                    'patterns_detected' => count($patterns),
                ]);
            }

            Log::info('Pattern detection completed', [
                'user_id' => $this->user->id,
                'patterns_detected' => count($patterns),
            ]);
        } catch (Exception $e) {
            $transaction->setStatus(SpanStatus::internalError());
            $transaction->finish();

            \Sentry\captureException($e);

            if ($integration) {
                $store->recordStatus($integration, $task, 'failed', [
                    'error' => $e->getMessage(),
                ]);
            }

            Log::error('Pattern detection failed', [
                'user_id' => $this->user->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function taskDefinition(): TaskDefinition
    {
        return new TaskDefinition(
            key: 'flint_pattern_detection',
            name: 'Flint Pattern Detection',
            description: 'Weekly pattern detection across the user\'s Flint data.',
            jobClass: self::class,
            appliesTo: ['integration'],
            queue: 'flint',
        );
    }
}
