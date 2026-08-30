<?php

namespace App\Jobs\Flint;

use App\Models\User;
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
use Throwable;

/**
 * Fires the outbound webhook for a once-daily Flint routine that isn't the
 * digest — topic review, reading-list curation, or the news roundup.
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

    public int $tries = 3;

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public User $user,
        public string $routine,
        public string $localDate,
        public string $timezone,
    ) {}

    /**
     * Cache key marking that this routine has already been triggered for this
     * user's local date.
     */
    public static function markerKey(int|string $userId, string $localDate, string $routine): string
    {
        return "flint:routine-triggered:{$routine}:{$userId}:{$localDate}";
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
        $markerKey = self::markerKey($this->user->id, $this->localDate, $this->routine);

        if (! Cache::add($markerKey, true, $this->markerTtlSeconds())) {
            Log::info('Flint routine trigger skipped (already triggered)', [
                'user_id' => $this->user->id,
                'routine' => $this->routine,
                'local_date' => $this->localDate,
            ]);

            return;
        }

        // Second-line guard. The Redis marker is cleared when a run fails, so a
        // retry after a run that already did its work — wrote a digest, touched
        // topics — would otherwise repeat it. The recorded success carries the
        // local date it was for.
        $lastSuccess = $store->getTaskExecutions($integration)[$task->key]['last_success'] ?? null;

        if (is_array($lastSuccess) && ($lastSuccess['local_date'] ?? null) === $this->localDate) {
            Log::info('Flint routine trigger skipped (already succeeded for this local date)', [
                'user_id' => $this->user->id,
                'routine' => $this->routine,
                'local_date' => $this->localDate,
            ]);

            return;
        }

        $store->recordStatus($integration, $task, 'pending', [
            'local_date' => $this->localDate,
            'error' => null,
        ]);

        $payload = [
            'user_id' => (string) $this->user->id,
            'routine' => $this->routine,
            'local_date' => $this->localDate,
            'timezone' => $this->timezone,
            'idempotency_key' => $markerKey,
        ];

        try {
            $result = $driver->for($this->routine)->run($this->user, $this->routine, $payload);
        } catch (Throwable $exception) {
            Cache::forget($markerKey);
            $store->recordStatus($integration, $task, 'failed', [
                'local_date' => $this->localDate,
                'error' => redact_sensitive_urls($exception->getMessage()),
            ]);

            throw $exception;
        }

        if ($result->status === 'not_applicable') {
            Cache::forget($markerKey);
            $store->recordStatus($integration, $task, 'not_applicable', [
                'local_date' => $this->localDate,
                'error' => null,
            ] + $result->details);

            return;
        }

        $store->recordStatus($integration, $task, 'success', [
            'local_date' => $this->localDate,
        ] + $result->details);

        Log::info('Flint routine triggered', [
            'user_id' => $this->user->id,
            'routine' => $this->routine,
            'local_date' => $this->localDate,
            'timezone' => $this->timezone,
            'driver' => $driver->driverName($this->routine),
        ]);
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
}
