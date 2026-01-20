<?php

namespace App\Jobs\Flint;

use App\Models\Block;
use App\Models\Event;
use App\Models\User;
use App\Notifications\DailyDigestReady;
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

class SendDigestNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60; // 1 minute

    public function __construct(
        public User $user,
        public string $scheduleTime
    ) {}

    public function handle(): void
    {
        $transactionContext = new TransactionContext;
        $transactionContext->setName('flint.send_digest_notification');
        $transactionContext->setOp('job');
        $transaction = \Sentry\startTransaction($transactionContext);

        SentrySdk::getCurrentHub()->setSpan($transaction);

        // Set user context for Sentry
        \Sentry\configureScope(function (\Sentry\State\Scope $scope) {
            $scope->setUser([
                'id' => $this->user->id,
                'email' => $this->user->email,
            ]);
            $scope->setTag('job_type', 'send_digest_notification');
        });

        try {
            $period = $this->getDigestPeriod($this->scheduleTime);

            Log::info('Sending digest notification', [
                'user_id' => $this->user->id,
                'schedule_time' => $this->scheduleTime,
                'period' => $period,
            ]);

            // Find the most recent digest block for this user created today
            $today = now()->startOfDay();
            $digestBlock = Block::whereIn('block_type', ['flint_summarised_headline', 'flint_digest'])
                ->whereHas('event', function ($query) use ($today) {
                    $query->where('service', 'flint')
                        ->whereIn('action', ['had_summary', 'had_analysis'])
                        ->whereHas('integration', function ($q) {
                            $q->where('user_id', $this->user->id);
                        })
                        ->where('time', '>=', $today);
                })
                ->with(['event.target'])
                ->orderBy('created_at', 'desc')
                ->first();

            if (! $digestBlock) {
                Log::warning('No digest block found for notification', [
                    'user_id' => $this->user->id,
                    'schedule_time' => $this->scheduleTime,
                    'period' => $period,
                ]);

                $transaction->setData([
                    'user_id' => $this->user->id,
                    'schedule_time' => $this->scheduleTime,
                    'period' => $period,
                    'digest_found' => false,
                ]);
                $transaction->finish();

                return;
            }

            // Get all blocks for this event (insights, actions, etc.)
            $allBlocks = Block::where('event_id', $digestBlock->event_id)->get();

            // Send notification (target is the day object)
            $this->user->notify(new DailyDigestReady(
                digestObject: $digestBlock->event->target,
                period: $period,
                blocks: $allBlocks->toArray()
            ));

            // Mark digest as sent in metadata
            $digestBlock->metadata = array_merge($digestBlock->metadata ?? [], [
                'notification_sent_at' => now()->toIso8601String(),
                'notification_period' => $period,
            ]);
            $digestBlock->save();

            $transaction->setData([
                'user_id' => $this->user->id,
                'schedule_time' => $this->scheduleTime,
                'period' => $period,
                'digest_block_id' => $digestBlock->id,
                'digest_found' => true,
                'notification_sent' => true,
            ]);

            $transaction->finish();

            Log::info('Digest notification sent', [
                'user_id' => $this->user->id,
                'schedule_time' => $this->scheduleTime,
                'period' => $period,
                'digest_block_id' => $digestBlock->id,
            ]);
        } catch (Exception $e) {
            $transaction->setStatus(SpanStatus::internalError());
            $transaction->finish();

            \Sentry\captureException($e);

            Log::error('Failed to send digest notification', [
                'user_id' => $this->user->id,
                'schedule_time' => $this->scheduleTime,
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
