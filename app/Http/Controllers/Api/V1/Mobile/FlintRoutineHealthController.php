<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Integration;
use App\Services\EffectiveTimezoneResolver;
use App\Services\Flint\FlintScheduleService;
use App\Services\Flint\RoutineConfig;
use App\Services\Flint\Routines\RoutineDriverManager;
use App\Services\TaskPipeline\TaskExecutionStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FlintRoutineHealthController extends Controller
{
    public function __invoke(
        Request $request,
        FlintScheduleService $schedule,
        RoutineDriverManager $drivers,
        TaskExecutionStore $executions,
        EffectiveTimezoneResolver $timezones,
    ): JsonResponse {
        $user = $request->user();
        $integration = Integration::query()
            ->where('user_id', $user->id)
            ->where('service', 'flint')
            ->where('instance_type', 'digest')
            ->first();
        $executionState = $integration ? $executions->getTaskExecutions($integration) : [];
        $routines = collect(['morning_digest', 'evening_digest', 'topics', 'reading_list', 'news_roundup'])
            ->map(function (string $routine) use ($user, $integration, $executionState, $schedule, $drivers): array {
                $driverRoutine = str_ends_with($routine, '_digest') ? 'digest' : $routine;
                $driver = $drivers->driverName($driverRoutine);
                $enabled = $schedule->enabled($user, $routine);
                $configured = $driver === 'webhook'
                    ? RoutineConfig::url($driverRoutine) !== null
                    : filled(config('services.flint_routine.cronxtools_url'));
                $attempt = $executionState["flint_routine_{$driverRoutine}"]['last_attempt'] ?? null;
                $success = $executionState["flint_routine_{$driverRoutine}"]['last_success'] ?? null;
                $period = $routine === 'morning_digest' ? 'morning' : ($routine === 'evening_digest' ? 'evening' : null);

                if ($period !== null && is_array($attempt) && ($attempt['period'] ?? null) !== $period) {
                    $attempt = null;
                }
                if ($period !== null && is_array($success) && ($success['period'] ?? null) !== $period) {
                    $success = null;
                }

                return [
                    'routine' => $routine,
                    'state' => ! $enabled ? 'disabled' : (! $configured ? 'unconfigured' : ($integration ? 'configured' : 'unavailable')),
                    'enabled' => $enabled,
                    'driver' => $driver,
                    'next_eligible_run' => $enabled ? $schedule->nextEligibleRun($user, $routine)->toIso8601String() : null,
                    'last_attempt' => $attempt ? $this->safeAttempt($attempt) : null,
                    'last_persisted_output' => $this->lastOutput($user->id, $driverRoutine, $period, $success),
                    'failure' => is_array($attempt) && in_array($attempt['status'] ?? null, ['failed', 'retrying'], true) ? [
                        'status' => $attempt['status'],
                        'occurred_at' => $attempt['completed_at'] ?? $attempt['started_at'] ?? null,
                    ] : null,
                ];
            });

        return response()->json([
            'data' => $routines->all(),
            'meta' => [
                'effective_timezone' => $timezones->timezoneFor($user),
                'account_id' => (string) $user->id,
                'management_url' => url('/flint'),
            ],
        ]);
    }

    /** @param array<string,mixed> $attempt */
    private function safeAttempt(array $attempt): array
    {
        return array_filter([
            'status' => $attempt['status'] ?? null,
            'accepted_at' => $attempt['accepted_at'] ?? null,
            'started_at' => $attempt['started_at'] ?? null,
            'completed_at' => $attempt['completed_at'] ?? null,
            'run_uuid' => $attempt['run_uuid'] ?? null,
        ], fn (mixed $value) => $value !== null);
    }

    /** @param array<string,mixed>|null $success */
    private function lastOutput(string $userId, string $routine, ?string $period, ?array $success): ?array
    {
        if ($routine === 'topics') {
            return $success ? ['persisted_at' => $success['persisted_at'] ?? $success['completed_at'] ?? null] : null;
        }

        $event = Event::query()
            ->where('service', 'flint')
            ->where('action', 'had_summary')
            ->whereHas('integration', fn ($query) => $query->where('user_id', $userId))
            ->when($routine === 'digest', fn ($query) => $query->where('event_metadata->routine', 'digest'))
            ->when($routine !== 'digest', fn ($query) => $query->where('event_metadata->routine', $routine))
            ->when($period, fn ($query) => $query->where('event_metadata->period', $period))
            ->latest('created_at')
            ->first();

        return $event ? ['digest_id' => (string) $event->id, 'persisted_at' => $event->created_at?->toIso8601String()] : null;
    }
}
