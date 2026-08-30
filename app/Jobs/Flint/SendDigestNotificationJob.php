<?php

namespace App\Jobs\Flint;

use App\Models\Event;
use App\Models\User;
use App\Notifications\DailyDigestReady;
use App\Services\EffectiveTimezoneResolver;
use App\Services\FlintDigestService;
use App\Services\TaskPipeline\TaskDefinition;
use App\Services\TaskPipeline\TaskExecutionStore;
use Carbon\Carbon;
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

/**
 * Notifies the user that a Flint digest has landed.
 *
 * Dispatched by NotifyOnDigestReadyTask on creation of a `flint/had_summary`
 * event, so it reads that event directly: the title and summary prose live in
 * `event_metadata`, written by the routine through the create-flint-digest
 * MCP tool.
 *
 * Records TaskExecution rows against the user's Flint integration, alongside the
 * dispatch side's rows, so /admin/task-pipeline shows whether a digest was
 * actually announced. The "no digest found" case is recorded as not_applicable
 * rather than returning silently — that was the failure mode that hid this job
 * looking for block types the routine never writes.
 */
class SendDigestNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60; // 1 minute

    public function __construct(
        public User $user,
        public string $scheduleTime,
        public ?string $period = null,
        public ?string $digestEventId = null,
    ) {}

    public function handle(FlintDigestService $digests, TaskExecutionStore $store): void
    {
        $integration = $digests->resolveIntegration($this->user);
        $task = $this->taskDefinition();
        $period = $this->period ?? $this->getDigestPeriod($this->scheduleTime);

        $transactionContext = new TransactionContext;
        $transactionContext->setName('flint.send_digest_notification');
        $transactionContext->setOp('job');
        $transaction = \Sentry\startTransaction($transactionContext);

        SentrySdk::getCurrentHub()->setSpan($transaction);

        // Set user context for Sentry
        \Sentry\configureScope(function (Scope $scope) {
            $scope->setUser([
                'id' => $this->user->id,
                'email' => $this->user->email,
            ]);
            $scope->setTag('job_type', 'send_digest_notification');
        });

        try {
            $digest = $this->findDigest($period);

            if (! $digest) {
                Log::warning('No digest event found for notification', [
                    'user_id' => $this->user->id,
                    'schedule_time' => $this->scheduleTime,
                    'period' => $period,
                ]);

                $store->recordStatus($integration, $task, 'not_applicable', [
                    'period' => $period,
                    'reason' => 'No digest event found for this period.',
                    'error' => null,
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

            $metadata = $digest->event_metadata ?? [];

            $this->user->notify(new DailyDigestReady(
                digestObject: $digest->target,
                period: $metadata['period'] ?? $period,
                title: $metadata['title'] ?? null,
                summary: $metadata['summary'] ?? null,
                unansweredQuestionCount: $digest->blocks
                    ->where('block_type', 'flint_user_question')
                    ->filter(fn ($block) => is_null($block->metadata['answer'] ?? null))
                    ->count(),
            ));

            // Record that the digest has been announced, so a re-run doesn't
            // silently notify twice without a trace.
            $digest->event_metadata = array_merge($metadata, [
                'notification_sent_at' => now()->toIso8601String(),
            ]);
            $digest->save();

            $transaction->setData([
                'user_id' => $this->user->id,
                'schedule_time' => $this->scheduleTime,
                'period' => $period,
                'digest_event_id' => $digest->id,
                'digest_found' => true,
                'notification_sent' => true,
            ]);

            $transaction->finish();

            $store->recordStatus($integration, $task, 'success', [
                'period' => $period,
                'digest_event_id' => $digest->id,
                'error' => null,
            ]);

            Log::info('Digest notification sent', [
                'user_id' => $this->user->id,
                'schedule_time' => $this->scheduleTime,
                'period' => $period,
                'digest_event_id' => $digest->id,
            ]);
        } catch (Exception $e) {
            $transaction->setStatus(SpanStatus::internalError());
            $transaction->finish();

            \Sentry\captureException($e);

            $store->recordStatus($integration, $task, 'failed', [
                'period' => $period,
                'error' => $e->getMessage(),
            ]);

            Log::error('Failed to send digest notification', [
                'user_id' => $this->user->id,
                'schedule_time' => $this->scheduleTime,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Built inline rather than registered in TaskRegistry, matching the dispatch
     * side. See docs/Architecture/TASK_PIPELINE.md.
     */
    private function taskDefinition(): TaskDefinition
    {
        return new TaskDefinition(
            key: 'flint_digest_notification',
            name: 'Flint Digest Notification',
            description: 'Announces a Flint digest once the routine has written it.',
            jobClass: self::class,
            appliesTo: ['integration'],
            queue: 'flint',
        );
    }

    /**
     * The digest to announce: the event we were handed, else the most recent
     * one for this period on the user's effective-local day.
     *
     * The `had_summary` event's `time` is anchored at the local date's 00:00
     * UTC marker (see FlintDigestService), so the day is bounded in UTC against
     * that same local date rather than a server-tz day.
     */
    private function findDigest(string $period): ?Event
    {
        $localDate = app(EffectiveTimezoneResolver::class)->today($this->user)->toDateString();

        return Event::query()
            ->whereIn('integration_id', $this->user->integrations()->pluck('id'))
            ->where('service', 'flint')
            ->where('action', 'had_summary')
            ->when(
                $this->digestEventId,
                fn ($query) => $query->whereKey($this->digestEventId),
                fn ($query) => $query
                    ->whereJsonContains('event_metadata->period', $period)
                    ->whereBetween('time', [
                        Carbon::parse($localDate, 'UTC')->startOfDay(),
                        Carbon::parse($localDate, 'UTC')->endOfDay(),
                    ]),
            )
            ->with(['blocks', 'target'])
            ->orderByDesc('created_at')
            ->first();
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
