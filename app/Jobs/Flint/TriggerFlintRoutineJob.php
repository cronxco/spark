<?php

namespace App\Jobs\Flint;

use App\Models\ActionProgress;
use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use App\Services\Flint\FlintRunToken;
use App\Services\Flint\FlintScheduleService;
use App\Services\Flint\RoutineConfig;
use App\Services\Flint\Routines\RoutineDriverManager;
use App\Services\FlintDigestService;
use App\Services\TaskPipeline\TaskDefinition;
use App\Services\TaskPipeline\TaskExecutionStore;
use App\Support\FlintDigestDay;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fires the outbound webhook for a once-daily Flint routine that isn't the
 * digest — topic review, reading-list curation, the news roundup, or the money check-ins.
 *
 * Same division of labour as {@see TriggerFlintDigestRoutineJob}: Spark owns
 * the timing (resolved in the user's effective timezone), the routine owns the
 * work and calls back through the Spark MCP tools. Idempotent per
 * (user, local date, routine) via a Redis marker that expires with the local day.
 *
 * Like the digest job, this records TaskExecution rows against the user's Flint
 * integration so /admin/task-pipeline can show whether each routine fired today,
 * when, and whether it succeeded — including the routines that are not
 * configured yet, which record as `not_applicable` rather than going dark.
 */
class TriggerFlintRoutineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MAX_SCHEDULED_ATTEMPTS = 2;

    public int $tries = 2;

    public int $timeout = 660;

    public bool $failOnTimeout = true;

    /**
     * Long enough for a run that was accepted but not yet answered to write its
     * digest, so the retry's successfulScheduledEventExists() check can see it.
     *
     * @var array<int, int>
     */
    public array $backoff = [300];

    public string $runUuid;

    public string $runToken;

    public function __construct(
        public User $user,
        public string $routine,
        public string $localDate,
        public string $timezone,
        public bool $force = false,
        ?string $runUuid = null,
        public ?int $progressId = null,
        public ?string $requestedPeriod = null,
        public ?string $driverOverride = null,
        public ?string $occurrence = null,
    ) {
        $this->runUuid = $runUuid ?? (string) Str::uuid();
        $this->runToken = app(FlintRunToken::class)->issue([
            'run_uuid' => $this->runUuid,
            'user_id' => (string) $this->user->id,
            'routine' => $this->routine,
            'skill' => RoutineConfig::SKILLS[$this->routine] ?? '',
            'local_date' => $this->localDate,
            'period' => $this->period(),
            'trigger_source' => $this->isScheduled() ? 'scheduled' : 'manual',
        ] + $this->occurrenceClaim());
    }

    /**
     * Cache key marking that this routine has already been triggered for this
     * user's local date.
     */
    public static function markerKey(int|string $userId, string $localDate, string $routine, ?string $occurrence = null): string
    {
        return "flint:routine-triggered:{$routine}:{$userId}:{$localDate}" . self::occurrenceSuffix($occurrence);
    }

    public static function attemptsKey(int|string $userId, string $localDate, string $routine, ?string $occurrence = null): string
    {
        return "flint:routine-attempts:{$routine}:{$userId}:{$localDate}" . self::occurrenceSuffix($occurrence);
    }

    /**
     * A schedule with one daily time has no occurrence, so its keys stay exactly as they were; a
     * schedule with several times or a cron gets one key per slot (`:0930`).
     */
    private static function occurrenceSuffix(?string $occurrence): string
    {
        return $occurrence === null ? '' : ':' . str_replace(':', '', $occurrence);
    }

    public function handle(FlintDigestService $digests, TaskExecutionStore $store): void
    {
        if ($this->routine === 'digest' || ! RoutineConfig::isKnown($this->routine)) {
            Log::warning('Unknown Flint routine; skipping trigger', [
                'user_id' => $this->user->id,
                'routine' => $this->routine,
            ]);

            return;
        }

        $integration = $digests->resolveIntegration($this->user);
        $task = $this->taskDefinition();
        $driver = app(RoutineDriverManager::class);
        $selectedDriver = $driver->driverName($this->routine, $this->driverOverride, $this->user);
        $markerKey = self::markerKey($this->user->id, $this->localDate, $this->routine, $this->occurrence);

        if ($this->isScheduled()
            && ! Cache::add($markerKey, $this->runUuid, $this->markerTtlSeconds())
            && Cache::get($markerKey) !== $this->runUuid) {
            Log::info('Flint routine trigger skipped (already triggered)', [
                'user_id' => $this->user->id,
                'routine' => $this->routine,
                'local_date' => $this->localDate,
            ]);

            return;
        }

        // A routine that is still running is not started again: hand the slot back so the next
        // tick can take it (or a later slot replaces it).
        if ($this->isScheduled() && $this->occurrence !== null
            && app(FlintScheduleService::class)->isRunning($this->user, $this->routine)) {
            Cache::forget($markerKey);
            Log::info('Flint routine trigger skipped (previous run still in flight)', [
                'user_id' => $this->user->id,
                'routine' => $this->routine,
                'occurrence' => $this->occurrence,
            ]);

            return;
        }

        // Second-line guard. The Redis marker is cleared when a run fails, so a
        // retry after a run that already did its work — wrote a digest, touched
        // topics — would otherwise repeat it. The recorded success carries the
        // local date it was for.
        if ($this->isScheduled() && $this->occurrence === null && $this->routine !== 'topics' && $this->successfulScheduledEventExists()) {
            Log::info('Flint routine trigger skipped (scheduled event already exists)', [
                'user_id' => $this->user->id,
                'routine' => $this->routine,
                'local_date' => $this->localDate,
            ]);

            return;
        }

        if ($this->isScheduled()) {
            $attemptsKey = self::attemptsKey($this->user->id, $this->localDate, $this->routine, $this->occurrence);
            Cache::add($attemptsKey, 0, $this->markerTtlSeconds());
            if ((int) Cache::get($attemptsKey, 0) >= self::MAX_SCHEDULED_ATTEMPTS) {
                Cache::put($markerKey, 'terminal-failure', $this->markerTtlSeconds());
                $this->recordTerminalFailure($store, $integration, 'Flint routine failed after two attempts.');

                return;
            }
        }

        $lastSuccess = $store->getTaskExecutions($integration)[$task->key]['last_success'] ?? null;

        if ($this->isScheduled()
            && is_array($lastSuccess)
            && ($lastSuccess['local_date'] ?? null) === $this->localDate
            && ($lastSuccess['occurrence'] ?? null) === $this->occurrence
            && ($lastSuccess['trigger_source'] ?? null) === 'scheduled') {
            Log::info('Flint routine trigger skipped (already succeeded for this local date)', [
                'user_id' => $this->user->id,
                'routine' => $this->routine,
                'local_date' => $this->localDate,
            ]);

            return;
        }

        $store->recordStatus($integration, $task, 'pending', [
            'local_date' => $this->localDate,
            'period' => $this->period(),
            'run_uuid' => $this->runUuid,
            'occurrence' => $this->occurrence,
            'triggered_by' => $this->isScheduled() ? 'scheduled' : 'manual',
            'trigger_source' => $this->isScheduled() ? 'scheduled' : 'manual',
            'driver' => $selectedDriver,
            'error' => null,
        ]);

        $payload = [
            'user_id' => (string) $this->user->id,
            'routine' => $this->routine,
            'local_date' => $this->localDate,
            'timezone' => $this->timezone,
            'idempotency_key' => $markerKey,
            'period' => $this->period(),
            'run_token' => $this->runToken,
        ] + $this->modePayload() + $this->occurrenceClaim();

        $progress = $this->progress();

        if ($this->isScheduled()) {
            Cache::increment(self::attemptsKey($this->user->id, $this->localDate, $this->routine, $this->occurrence));
        }

        try {
            $result = $driver->for($this->routine, $this->driverOverride, $this->user)->run($this->user, $this->routine, $payload, $progress);
        } catch (Throwable $exception) {
            $store->recordStatus($integration, $task, 'retrying', [
                'local_date' => $this->localDate,
                'period' => $this->period(),
                'run_uuid' => $this->runUuid,
                'occurrence' => $this->occurrence,
                'trigger_source' => $this->isScheduled() ? 'scheduled' : 'manual',
                'driver' => $selectedDriver,
                'attempts' => $this->attempts(),
                'error' => redact_sensitive_urls($exception->getMessage()),
            ]);
            $progress?->updateProgress('retrying', 'Flint routine will retry', 10, $progress->details ?? []);

            throw $exception;
        }

        if ($result->status === 'not_applicable') {
            if ($this->isScheduled() && Cache::get($markerKey) === $this->runUuid) {
                Cache::forget($markerKey);
            }
            $store->recordStatus($integration, $task, 'not_applicable', [
                'local_date' => $this->localDate,
                'period' => $this->period(),
                'run_uuid' => $this->runUuid,
                'occurrence' => $this->occurrence,
                'trigger_source' => $this->isScheduled() ? 'scheduled' : 'manual',
                'driver' => $selectedDriver,
                'error' => null,
            ] + $result->details);
            $progress?->markFailed((string) ($result->details['reason'] ?? 'Routine is not configured.'));

            return;
        }

        $transition = $store->recordAcceptedUnlessSucceeded($integration, $task, $this->runUuid, [
            'local_date' => $this->localDate,
            'period' => $this->period(),
            'triggered_by' => $this->isScheduled() ? 'scheduled' : 'manual',
            'trigger_source' => $this->isScheduled() ? 'scheduled' : 'manual',
            'driver' => $selectedDriver,
        ] + $result->details);
        if ($transition['completed']) {
            $progress?->markCompleted($transition['execution']);

            return;
        }

        $progress?->updateProgress('accepted', 'Flint accepted the routine run', 50, $result->details);

        Log::info('Flint routine triggered', [
            'user_id' => $this->user->id,
            'routine' => $this->routine,
            'local_date' => $this->localDate,
            'timezone' => $this->timezone,
            'driver' => $selectedDriver,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $markerKey = self::markerKey($this->user->id, $this->localDate, $this->routine, $this->occurrence);
        if ($this->isScheduled()) {
            $attempts = (int) Cache::get(self::attemptsKey($this->user->id, $this->localDate, $this->routine, $this->occurrence), 0);
            $attempts >= self::MAX_SCHEDULED_ATTEMPTS
                ? Cache::put($markerKey, 'terminal-failure', $this->markerTtlSeconds())
                : Cache::forget($markerKey);
        }

        $integration = app(FlintDigestService::class)->resolveIntegration($this->user);
        app(TaskExecutionStore::class)->recordStatus($integration, $this->taskDefinition(), 'failed', [
            'local_date' => $this->localDate,
            'period' => $this->period(),
            'run_uuid' => $this->runUuid,
            'occurrence' => $this->occurrence,
            'triggered_by' => $this->isScheduled() ? 'scheduled' : 'manual',
            'trigger_source' => $this->isScheduled() ? 'scheduled' : 'manual',
            'driver' => app(RoutineDriverManager::class)->driverName($this->routine, $this->driverOverride, $this->user),
            'attempts' => $this->attempts(),
            'completed_at' => now()->toIso8601String(),
            'error' => redact_sensitive_urls($exception?->getMessage() ?? 'Flint routine failed.'),
        ], promoteSuccess: false);
        $this->progress()?->markFailed(redact_sensitive_urls($exception?->getMessage() ?? 'Flint routine failed.'));
    }

    /**
     * Built inline rather than registered in TaskRegistry: this is a cron job
     * outside the event/block/object dependency pipeline, so it only needs the
     * shape TaskExecutionStore records against. See docs/Architecture/TASK_PIPELINE.md.
     */
    private function taskDefinition(): TaskDefinition
    {
        $name = ucwords(str_replace('_', ' ', $this->routine));

        return new TaskDefinition(
            key: "flint_routine_{$this->routine}",
            name: "Flint {$name} Routine",
            description: "Outbound trigger for the Flint {$name} routine.",
            jobClass: self::class,
            appliesTo: ['integration'],
            queue: 'flint',
        );
    }

    /**
     * Seconds remaining until the end of the effective-local day, so the marker
     * naturally expires once the slot has passed.
     */
    private function markerTtlSeconds(): int
    {
        $endOfDay = Carbon::parse($this->localDate, $this->timezone)->endOfDay();

        // Carbon 3 returns a signed float here, which max() propagates into this
        // method's int return type — cast rather than rely on implicit conversion.
        return max(60, (int) now()->diffInSeconds($endOfDay, false));
    }

    private function isScheduled(): bool
    {
        return ! $this->force;
    }

    private function period(): string
    {
        if ($this->requestedPeriod !== null) {
            return $this->requestedPeriod;
        }

        return match ($this->routine) {
            'news_roundup' => 'morning',
            'reading_list', 'topics' => 'evening',
            default => 'morning',
        };
    }

    /**
     * Routines that share a skill tell it which job it is doing.
     *
     * @return array<string, string>
     */
    private function modePayload(): array
    {
        return match ($this->routine) {
            'money_weekly' => ['mode' => 'weekly'],
            'money_close' => ['mode' => 'close'],
            default => [],
        };
    }

    /**
     * @return array<string, string>
     */
    private function occurrenceClaim(): array
    {
        return $this->occurrence === null ? [] : ['occurrence' => $this->occurrence];
    }

    private function progress(): ?ActionProgress
    {
        return $this->progressId ? ActionProgress::find($this->progressId) : null;
    }

    private function successfulScheduledEventExists(): bool
    {
        return Event::query()
            ->whereHas('integration', fn ($query) => $query->where('user_id', $this->user->id))
            ->tap(fn ($query) => FlintDigestDay::forScheduledSlot($query, $this->routine, $this->period(), $this->localDate))
            ->exists();
    }

    private function recordTerminalFailure(TaskExecutionStore $store, Integration $integration, string $message): void
    {
        $store->recordStatus($integration, $this->taskDefinition(), 'failed', [
            'local_date' => $this->localDate,
            'period' => $this->period(),
            'run_uuid' => $this->runUuid,
            'occurrence' => $this->occurrence,
            'triggered_by' => 'scheduled',
            'trigger_source' => 'scheduled',
            'driver' => app(RoutineDriverManager::class)->driverName($this->routine, $this->driverOverride, $this->user),
            'attempts' => self::MAX_SCHEDULED_ATTEMPTS,
            'completed_at' => now()->toIso8601String(),
            'error' => $message,
        ], promoteSuccess: false);
        $this->progress()?->markFailed($message);
    }
}
