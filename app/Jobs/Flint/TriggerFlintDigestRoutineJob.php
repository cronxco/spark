<?php

namespace App\Jobs\Flint;

use App\Models\ActionProgress;
use App\Models\Event;
use App\Models\User;
use App\Services\Flint\FlintRunToken;
use App\Services\Flint\RoutineConfig;
use App\Services\Flint\Routines\RoutineDriverManager;
use App\Services\FlintDigestService;
use App\Services\TaskPipeline\TaskDefinition;
use App\Services\TaskPipeline\TaskExecutionStore;
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
 * Fires the outbound webhook that asks the Claude Code Routine to generate a
 * Flint digest. Spark owns the timing (resolved in the user's effective
 * timezone); the routine owns generation and calls back via the
 * create-flint-digest MCP tool.
 *
 * Idempotent per (user, local date, period): a Redis marker plus an
 * existing-digest guard ensure the routine is only triggered once per slot.
 */
class TriggerFlintDigestRoutineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * How long one dispatch suppresses the next. Longer than the routine's own
     * timeout, so a run still in flight is never dispatched twice.
     */
    private const RETRY_GRACE_SECONDS = 1800;

    /**
     * Total scheduled dispatches allowed per user, day and period. Enough to
     * ride out a transient failure, few enough that a broken routine does not
     * retry until midnight.
     */
    private const MAX_SCHEDULED_ATTEMPTS = 3;

    public int $tries = 3;

    public int $timeout = 660;

    public bool $failOnTimeout = true;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public string $runUuid;

    public string $runToken;

    public function __construct(
        public User $user,
        public string $period,
        public string $localDate,
        public string $timezone,
        public string $triggerReason,
        public ?string $sleepScoreEventId = null,
        public bool $force = false,
        ?string $runUuid = null,
        public ?int $progressId = null,
    ) {
        $this->runUuid = $runUuid ?? (string) Str::uuid();
        $this->runToken = app(FlintRunToken::class)->issue([
            'run_uuid' => $this->runUuid,
            'user_id' => (string) $this->user->id,
            'routine' => 'digest',
            'skill' => RoutineConfig::SKILLS['digest'],
            'local_date' => $this->localDate,
            'period' => $this->period,
            'trigger_source' => $this->isScheduled() ? 'scheduled' : 'manual',
        ]);
    }

    /**
     * Cache key marking that this user's digest for this local date + period has
     * already been triggered.
     */
    public static function markerKey(int|string $userId, string $localDate, string $period): string
    {
        return "flint:digest-triggered:{$userId}:{$localDate}:{$period}";
    }

    /**
     * Cache key counting how many times this digest has been dispatched today,
     * so a routine that is simply broken is retried a few times rather than
     * every grace window until midnight.
     */
    public static function attemptsKey(int|string $userId, string $localDate, string $period): string
    {
        return "flint:digest-attempts:{$userId}:{$localDate}:{$period}";
    }

    public function handle(): void
    {
        $markerKey = self::markerKey($this->user->id, $this->localDate, $this->period);

        if ($this->isScheduled()
            && ! Cache::add($markerKey, $this->runUuid, $this->markerTtlSeconds())
            && Cache::get($markerKey) !== $this->runUuid) {
            Log::info('Flint routine trigger skipped (already triggered)', [
                'user_id' => $this->user->id,
                'period' => $this->period,
                'local_date' => $this->localDate,
            ]);

            return;
        }

        if ($this->isScheduled() && $this->digestAlreadyExists()) {
            Log::info('Flint routine trigger skipped (digest already exists)', [
                'user_id' => $this->user->id,
                'period' => $this->period,
                'local_date' => $this->localDate,
            ]);

            return;
        }

        // The grace window has lapsed and no digest exists, so this is a retry.
        // Cap it: a routine that is broken should not be re-triggered every half
        // hour until midnight.
        if ($this->isScheduled()) {
            $attemptsKey = self::attemptsKey($this->user->id, $this->localDate, $this->period);
            Cache::add($attemptsKey, 0, $this->attemptsTtlSeconds());

            if (Cache::increment($attemptsKey) > self::MAX_SCHEDULED_ATTEMPTS) {
                Log::warning('Flint routine trigger skipped (attempt limit reached)', [
                    'user_id' => $this->user->id,
                    'period' => $this->period,
                    'local_date' => $this->localDate,
                    'max_attempts' => self::MAX_SCHEDULED_ATTEMPTS,
                ]);

                return;
            }
        }

        $integration = app(FlintDigestService::class)->resolveIntegration($this->user);
        $task = $this->taskDefinition();
        $store = app(TaskExecutionStore::class);
        $progress = $this->progress();

        $store->recordStatus($integration, $task, 'pending', [
            'triggered_by' => $this->triggerReason,
            'trigger_source' => $this->isScheduled() ? 'scheduled' : 'manual',
            'run_uuid' => $this->runUuid,
            'local_date' => $this->localDate,
            'period' => $this->period,
            'error' => null,
        ]);

        $payload = [
            'user_id' => (string) $this->user->id,
            'period' => $this->period,
            'local_date' => $this->localDate,
            'timezone' => $this->timezone,
            'trigger_reason' => $this->triggerReason,
            'sleep_score_event_id' => $this->sleepScoreEventId,
            'idempotency_key' => $markerKey,
            'run_token' => $this->runToken,
        ];

        $driver = app(RoutineDriverManager::class);

        try {
            $result = $driver->for('digest')->run($this->user, 'digest', $payload, $progress);
        } catch (Throwable $exception) {
            $store->recordStatus($integration, $task, 'retrying', [
                'triggered_by' => $this->triggerReason,
                'trigger_source' => $this->isScheduled() ? 'scheduled' : 'manual',
                'run_uuid' => $this->runUuid,
                'local_date' => $this->localDate,
                'period' => $this->period,
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
                'triggered_by' => $this->triggerReason,
                'trigger_source' => $this->isScheduled() ? 'scheduled' : 'manual',
                'run_uuid' => $this->runUuid,
                'local_date' => $this->localDate,
                'period' => $this->period,
                'error' => null,
            ] + $result->details);
            $progress?->markFailed((string) ($result->details['reason'] ?? 'Routine is not configured.'));

            return;
        }

        $store->recordStatus($integration, $task, 'success', [
            'triggered_by' => $this->triggerReason,
            'trigger_source' => $this->isScheduled() ? 'scheduled' : 'manual',
            'run_uuid' => $this->runUuid,
            'local_date' => $this->localDate,
            'period' => $this->period,
        ] + $result->details, promoteSuccess: $this->isScheduled());
        $progress?->markCompleted($result->details);

        Log::info('Flint routine webhook triggered', [
            'user_id' => $this->user->id,
            'period' => $this->period,
            'local_date' => $this->localDate,
            'timezone' => $this->timezone,
            'trigger_reason' => $this->triggerReason,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $markerKey = self::markerKey($this->user->id, $this->localDate, $this->period);
        if ($this->isScheduled() && Cache::get($markerKey) === $this->runUuid) {
            Cache::forget($markerKey);
        }

        $integration = app(FlintDigestService::class)->resolveIntegration($this->user);
        app(TaskExecutionStore::class)->recordStatus($integration, $this->taskDefinition(), 'failed', [
            'triggered_by' => $this->triggerReason,
            'trigger_source' => $this->isScheduled() ? 'scheduled' : 'manual',
            'run_uuid' => $this->runUuid,
            'local_date' => $this->localDate,
            'period' => $this->period,
            'attempts' => $this->attempts(),
            'completed_at' => now()->toIso8601String(),
            'error' => redact_sensitive_urls($exception?->getMessage() ?? 'Flint routine failed.'),
        ], promoteSuccess: false);
        $this->progress()?->markFailed(redact_sensitive_urls($exception?->getMessage() ?? 'Flint routine failed.'));
    }

    private function taskDefinition(): TaskDefinition
    {
        return new TaskDefinition(
            key: 'flint_routine_digest',
            name: 'Flint Digest Routine',
            description: 'Outbound trigger for the Flint digest routine.',
            jobClass: self::class,
            appliesTo: ['integration'],
            queue: 'flint',
        );
    }

    /**
     * Whether a Flint digest for this user/period/local date already exists,
     * scoped to the user's own Flint integration(s).
     */
    private function digestAlreadyExists(): bool
    {
        $start = Carbon::parse($this->localDate, $this->timezone)->startOfDay()->utc();
        $end = Carbon::parse($this->localDate, $this->timezone)->endOfDay()->utc();

        return Event::query()
            ->where('service', 'flint')
            ->where('action', 'had_summary')
            ->whereHas('integration', fn ($q) => $q->where('user_id', $this->user->id))
            ->whereJsonContains('event_metadata->period', $this->period)
            ->where('event_metadata->routine', 'digest')
            ->where('event_metadata->trigger_source', 'scheduled')
            ->whereBetween('time', [$start, $end])
            ->exists();
    }

    /**
     * How long a dispatch suppresses the next one.
     *
     * This used to run to the end of the local day, which made the marker the
     * de facto proof that a digest existed — and it is not. The marker only
     * records that a webhook was *delivered*. If the routine then failed on the
     * far side without ever calling create-flint-digest, the job still recorded
     * success, the marker stood until midnight, and no digest was ever written:
     * exactly the shape of the missing evening digest on 6 September 2026.
     *
     * A grace window instead, comfortably longer than the routine's own
     * timeout. Once it lapses, digestAlreadyExists() — the authoritative check,
     * already in handle() — decides whether to skip or try again.
     */
    private function markerTtlSeconds(): int
    {
        $endOfDay = Carbon::parse($this->localDate, $this->timezone)->endOfDay();

        // Carbon 3 returns a signed float here, which max() propagates into this
        // method's int return type — cast rather than rely on implicit conversion.
        $untilEndOfDay = max(60, (int) now()->diffInSeconds($endOfDay, false));

        return min(self::RETRY_GRACE_SECONDS, $untilEndOfDay);
    }

    /**
     * Seconds until the end of the effective-local day — the life of the
     * attempt counter, which must not reset with the grace window.
     */
    private function attemptsTtlSeconds(): int
    {
        $endOfDay = Carbon::parse($this->localDate, $this->timezone)->endOfDay();

        return max(60, (int) now()->diffInSeconds($endOfDay, false));
    }

    private function isScheduled(): bool
    {
        return ! $this->force && $this->triggerReason !== 'manual';
    }

    private function progress(): ?ActionProgress
    {
        return $this->progressId ? ActionProgress::find($this->progressId) : null;
    }
}
