<?php

namespace App\Services\Flint;

use App\Models\Integration;
use App\Models\User;
use App\Services\TaskPipeline\TaskDefinition;
use App\Services\TaskPipeline\TaskExecutionStore;
use RuntimeException;

class FlintRunCompletionService
{
    public function __construct(
        private TaskExecutionStore $executions,
    ) {}

    /** @param array<string,mixed> $claims */
    public function complete(User $user, array $claims, ?string $eventId = null, bool $requireAccepted = true): array
    {
        $routine = (string) ($claims['routine'] ?? '');
        if (! RoutineConfig::isKnown($routine)) {
            throw new RuntimeException('The Flint run has an unknown routine.');
        }

        $integration = Integration::query()
            ->where('user_id', $user->id)
            ->where('service', 'flint')
            ->where('instance_type', 'digest')
            ->firstOrFail();
        $task = new TaskDefinition(
            key: "flint_routine_{$routine}",
            name: 'Flint ' . ucwords(str_replace('_', ' ', $routine)) . ' Routine',
            description: 'Verified Flint routine completion.',
            jobClass: self::class,
            appliesTo: ['integration'],
            queue: 'flint',
        );
        $current = $this->executions->getTaskExecutions($integration)[$task->key]['last_attempt'] ?? null;
        if (! is_array($current) || ($current['run_uuid'] ?? null) !== ($claims['run_uuid'] ?? null)) {
            if (! $requireAccepted) {
                return ['status' => 'untracked'];
            }

            throw new RuntimeException('The Flint run is not the current accepted attempt.');
        }

        return $this->executions->recordStatus($integration, $task, 'success', array_filter([
            'run_uuid' => $claims['run_uuid'],
            'local_date' => $claims['local_date'] ?? null,
            'period' => $claims['period'] ?? null,
            'trigger_source' => $claims['trigger_source'] ?? null,
            'driver' => $current['driver'] ?? null,
            'event_id' => $eventId,
            'persisted_at' => now()->toIso8601String(),
            'completed_at' => now()->toIso8601String(),
            'error' => null,
        ], fn (mixed $value) => $value !== null), promoteSuccess: true);
    }
}
