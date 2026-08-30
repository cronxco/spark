<?php

namespace App\Jobs\Flint;

use App\Models\Event;
use App\Models\User;
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

    public int $tries = 3;

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public User $user,
        public string $period,
        public string $localDate,
        public string $timezone,
        public string $triggerReason,
        public ?string $sleepScoreEventId = null,
        public bool $force = false,
    ) {}

    /**
     * Cache key marking that this user's digest for this local date + period has
     * already been triggered.
     */
    public static function markerKey(int|string $userId, string $localDate, string $period): string
    {
        return "flint:digest-triggered:{$userId}:{$localDate}:{$period}";
    }

    public function handle(): void
    {
        $markerKey = self::markerKey($this->user->id, $this->localDate, $this->period);

        if ($this->force) {
            Cache::put($markerKey, true, $this->markerTtlSeconds());
        } elseif (! Cache::add($markerKey, true, $this->markerTtlSeconds())) {
            Log::info('Flint routine trigger skipped (already triggered)', [
                'user_id' => $this->user->id,
                'period' => $this->period,
                'local_date' => $this->localDate,
            ]);

            return;
        }

        if (! $this->force && $this->digestAlreadyExists()) {
            Log::info('Flint routine trigger skipped (digest already exists)', [
                'user_id' => $this->user->id,
                'period' => $this->period,
                'local_date' => $this->localDate,
            ]);

            return;
        }

        $digest = app(FlintDigestService::class)->resolveDigestObject(
            $this->user,
            $this->period,
            Carbon::parse($this->localDate, $this->timezone),
        );
        $task = $this->taskDefinition();
        $store = app(TaskExecutionStore::class);

        $store->recordStatus($digest, $task, 'pending', [
            'triggered_by' => $this->triggerReason,
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
        ];

        $driver = app(RoutineDriverManager::class);

        try {
            $result = $driver->for('digest')->run($this->user, 'digest', $payload);
        } catch (Throwable $exception) {
            Cache::forget($markerKey);
            $store->recordStatus($digest, $task, 'failed', [
                'triggered_by' => $this->triggerReason,
                'error' => redact_sensitive_urls($exception->getMessage()),
            ]);

            throw $exception;
        }

        if ($result->status === 'not_applicable') {
            Cache::forget($markerKey);
            $store->recordStatus($digest, $task, 'not_applicable', [
                'triggered_by' => $this->triggerReason,
                'error' => null,
            ] + $result->details);

            return;
        }

        $store->recordStatus($digest, $task, 'success', ['triggered_by' => $this->triggerReason] + $result->details);

        Log::info('Flint routine webhook triggered', [
            'user_id' => $this->user->id,
            'period' => $this->period,
            'local_date' => $this->localDate,
            'timezone' => $this->timezone,
            'trigger_reason' => $this->triggerReason,
        ]);
    }

    private function taskDefinition(): TaskDefinition
    {
        return new TaskDefinition(
            key: "flint_digest_{$this->period}",
            name: 'Flint ' . ucfirst($this->period) . ' Digest',
            description: 'Outbound trigger for the Flint digest routine.',
            jobClass: self::class,
            appliesTo: ['object'],
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
            ->whereBetween('time', [$start, $end])
            ->exists();
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
}
