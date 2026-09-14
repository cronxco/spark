<?php

namespace App\Services\Flint;

use App\Models\Event;
use App\Models\EventObject;
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
    public function complete(User $user, array $claims, ?string $outputId = null, bool $requireAccepted = true): array
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

        return $this->executions->withTaskLock($integration, $task, function () use ($user, $claims, $outputId, $requireAccepted, $integration, $task, $routine): array {
            $runUuid = (string) $claims['run_uuid'];
            $current = $this->executions->trackedRunAttempt($integration, $task->key, $runUuid);
            if (! is_array($current)) {
                if (! $requireAccepted) {
                    return ['status' => 'untracked'];
                }

                throw new RuntimeException('The Flint run is not a tracked accepted attempt.');
            }

            $output = $this->persistedOutput($user, $routine, $runUuid, $outputId);

            return $this->executions->recordStatus($integration, $task, 'success', array_filter([
                'run_uuid' => $runUuid,
                'local_date' => $claims['local_date'] ?? null,
                'period' => $claims['period'] ?? null,
                'triggered_by' => $current['triggered_by'] ?? $claims['trigger_source'] ?? null,
                'trigger_source' => $claims['trigger_source'] ?? null,
                'driver' => $current['driver'] ?? null,
                'event_id' => $output['event_id'] ?? null,
                'output_id' => $output['output_id'],
                'persisted_at' => $output['persisted_at'],
                'completed_at' => now()->toIso8601String(),
                'error' => null,
            ], fn (mixed $value) => $value !== null), mergeLastAttempt: false, promoteSuccess: true);
        });
    }

    /** @return array{output_id:string,event_id?:string,persisted_at:string} */
    private function persistedOutput(User $user, string $routine, string $runUuid, ?string $outputId): array
    {
        if (! is_string($outputId) || $outputId === '') {
            throw new RuntimeException('A persisted output ID is required to complete the Flint run.');
        }

        if ($routine === 'topics') {
            $topic = EventObject::query()
                ->whereKey($outputId)
                ->where('user_id', $user->id)
                ->where('concept', 'flint')
                ->where('type', 'topic')
                ->first();
            $runUuids = is_array(data_get($topic?->metadata, 'run_uuids'))
                ? data_get($topic?->metadata, 'run_uuids')
                : [];
            if (! $topic || ! in_array($runUuid, $runUuids, true)) {
                throw new RuntimeException('The topic output is not associated with this Flint run.');
            }

            return [
                'output_id' => (string) $topic->id,
                'persisted_at' => $topic->updated_at->toIso8601String(),
            ];
        }

        $event = Event::query()
            ->whereKey($outputId)
            ->where('service', 'flint')
            ->where('action', 'had_summary')
            ->where('event_metadata->run_uuid', $runUuid)
            ->whereHas('integration', fn ($query) => $query->where('user_id', $user->id))
            ->first();
        if (! $event) {
            throw new RuntimeException('The digest output is not associated with this Flint run.');
        }

        return [
            'output_id' => (string) $event->id,
            'event_id' => (string) $event->id,
            'persisted_at' => $event->created_at->toIso8601String(),
        ];
    }
}
