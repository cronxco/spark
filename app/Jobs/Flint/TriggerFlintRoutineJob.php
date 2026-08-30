<?php

namespace App\Jobs\Flint;

use App\Models\User;
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
use Illuminate\Support\Facades\Http;
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

    /** Routine name => the config key holding its webhook URL. */
    public const ROUTINES = [
        'topics' => 'services.flint_routine.topics_url',
        'reading_list' => 'services.flint_routine.reading_list_url',
        'news_roundup' => 'services.flint_routine.news_roundup_url',
    ];

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
        if (! array_key_exists($this->routine, self::ROUTINES)) {
            Log::warning('Unknown Flint routine; skipping trigger', [
                'user_id' => $this->user->id,
                'routine' => $this->routine,
            ]);

            return;
        }

        $integration = $digests->resolveIntegration($this->user);
        $task = $this->taskDefinition();
        $url = config(self::ROUTINES[$this->routine]);

        if (empty($url)) {
            Log::info('Flint routine webhook URL not configured; skipping trigger', [
                'user_id' => $this->user->id,
                'routine' => $this->routine,
            ]);

            $store->recordStatus($integration, $task, 'not_applicable', [
                'local_date' => $this->localDate,
                'reason' => 'Webhook URL is not configured.',
                'error' => null,
            ]);

            return;
        }

        $markerKey = self::markerKey($this->user->id, $this->localDate, $this->routine);

        if (! Cache::add($markerKey, true, $this->markerTtlSeconds())) {
            Log::info('Flint routine trigger skipped (already triggered)', [
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

        $request = Http::withSentryTracing()->asJson()->timeout(20)->connectTimeout(5);

        if ($secret = config('services.flint_routine.secret')) {
            $request = $request->withToken($secret);
        }

        try {
            $response = $request->post($url, $payload);
        } catch (Throwable $exception) {
            Cache::forget($markerKey);
            $store->recordStatus($integration, $task, 'failed', [
                'local_date' => $this->localDate,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        if (! $response->successful()) {
            Log::error('Flint routine webhook returned a non-2xx response', [
                'user_id' => $this->user->id,
                'routine' => $this->routine,
                'status' => $response->status(),
            ]);

            Cache::forget($markerKey);
            $store->recordStatus($integration, $task, 'failed', [
                'local_date' => $this->localDate,
                'error' => "HTTP {$response->status()}: {$response->body()}",
            ]);
            $response->throw();
        }

        $store->recordStatus($integration, $task, 'success', [
            'local_date' => $this->localDate,
        ]);

        Log::info('Flint routine webhook triggered', [
            'user_id' => $this->user->id,
            'routine' => $this->routine,
            'local_date' => $this->localDate,
            'timezone' => $this->timezone,
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
